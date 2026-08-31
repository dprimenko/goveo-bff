<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Command;

use App\Billing\Domain\BillingMode;
use App\Billing\Domain\BillingPlan;
use App\Billing\Domain\BillingPlanRepository;
use App\Billing\Domain\BillingProduct;
use App\Billing\Domain\BillingProductRepository;
use App\Billing\Domain\Discount;
use App\Billing\Domain\DiscountRepository;
use App\Billing\Infrastructure\Stripe\StripeClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Exception\InvalidRequestException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Makes sure every active plan and discount exists in the Stripe account the
 * current key points at, creating only what is missing.
 *
 * This is what the `subrate` / `subplan` / `coupon` Firestore triggers used to do
 * on every write. Run it explicitly instead: the objects barely change, and a
 * trigger that mirrors to a payment provider on every save is a good way to
 * duplicate things nobody asked for.
 *
 * ⚠️ Run goveo:migrate:billing-plan-stripe-ids FIRST, or every plan that already
 * has a Price in Stripe will get a second one.
 *
 * Test and live are separate accounts with different contents, so this has to run
 * per environment.
 */
#[AsCommand(
    name: 'goveo:stripe:sync',
    description: 'Crea en Stripe los Prices y Coupons que falten para los planes y descuentos activos.',
)]
final class SyncStripeCommand extends Command
{
    public function __construct(
        private readonly StripeClientFactory $stripeFactory,
        private readonly BillingPlanRepository $plans,
        private readonly BillingProductRepository $products,
        private readonly DiscountRepository $discounts,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would be created without touching Stripe');
        $this->addOption('overwrite-ids', null, InputOption::VALUE_NONE, 'Permite sustituir un stripe_price_id existente por uno nuevo (ver aviso)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if (!$this->stripeFactory->isConfigured()) {
            $io->error('STRIPE_SECRET_KEY no está configurada.');

            return Command::FAILURE;
        }

        $stripe    = $this->stripeFactory->create();
        $key       = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        $overwrite = (bool) $input->getOption('overwrite-ids');
        $io->title(sprintf('Sincronizando con Stripe (%s)', str_starts_with($key, 'sk_test') ? 'TEST' : 'LIVE'));
        if ($dryRun) {
            $io->note('DRY RUN — no se crea nada.');
        }

        // --- productos → Products -------------------------------------------
        // Van primero: un Price necesita su producto, así que sin esto los
        // planes de una familia nueva no se pueden crear.
        $io->section('Productos');
        $ok = $created = 0;

        foreach ($this->allProducts() as $product) {
            $stripeId = $product->getStripeProductId();
            if ($stripeId !== null && $this->exists(fn () => $stripe->products->retrieve($stripeId))) {
                ++$ok;
                continue;
            }

            // Mismo criterio que con los Prices: si ya traía un id que aquí no
            // existe, es que la base apunta a otro entorno. Crear uno nuevo
            // sustituiría el id bueno.
            if ($stripeId !== null && !$overwrite) {
                $io->writeln(sprintf(
                    '  <error>OMITIDO</error> %-28s tiene id «%s», que no está en esta cuenta',
                    $product->getName(),
                    $stripeId,
                ));
                continue;
            }

            $io->writeln(sprintf('  <comment>CREAR</comment>   %s', $product->getName()));

            if (!$dryRun) {
                $stripeProduct = $stripe->products->create([
                    'name'     => $product->getName(),
                    'metadata' => ['goveo_product_id' => $product->getId()],
                ]);
                $product->syncStripeProductId($stripeProduct->id);
                $this->products->save($product);
            }
            ++$created;
        }
        $io->writeln(sprintf('  %d ya estaban, %d creados.', $ok, $created));

        // --- planes → Prices ------------------------------------------------
        $io->section('Planes');
        $ok = $created = $failed = 0;

        foreach ($this->allPlans() as $plan) {
            // Un plan gratuito nunca genera suscripción, así que no necesita Price.
            if ($plan->getMode() === BillingMode::Free) {
                continue;
            }

            $priceId = $plan->getStripePriceId();
            if ($priceId !== null && $this->exists(fn () => $stripe->prices->retrieve($priceId))) {
                ++$ok;
                continue;
            }

            // El plan ya traía un id que en ESTA cuenta no existe: casi siempre
            // significa que la base de datos apunta al otro entorno. Crear el Price
            // aquí sustituiría ese id y se perdería el del entorno bueno, así que
            // no se hace sin pedirlo a mano.
            if ($priceId !== null && !$overwrite) {
                $io->writeln(sprintf('  <error>OMITIDO</error> %-28s tiene id «%s», que no está en esta cuenta',
                    $plan->getName(), $priceId));
                ++$failed;
                continue;
            }

            $product = $this->products->findById($plan->getBillingProductId());
            if ($product === null || $product->getStripeProductId() === null) {
                $io->writeln(sprintf('  <error>FALLO</error>   %-28s sin producto de Stripe', $plan->getName()));
                ++$failed;
                continue;
            }

            $io->writeln(sprintf('  <comment>CREAR</comment>   %-28s %s %s / %d %s',
                $plan->getName(), number_format($plan->getAmountDecimal(), 2),
                strtoupper($plan->getCurrency()), $plan->getIntervalCount(), $plan->getInterval()->value));

            if (!$dryRun) {
                // El id no se puede elegir al crear un Price (la API antigua de
                // Plans sí lo permitía); se guarda el que devuelve Stripe.
                $price = $stripe->prices->create([
                    'product'     => $product->getStripeProductId(),
                    'unit_amount' => $plan->getAmountCents(),
                    'currency'    => $plan->getCurrency(),
                    'nickname'    => $plan->getName(),
                    'recurring'   => [
                        'interval'       => $plan->getInterval()->value,
                        'interval_count' => $plan->getIntervalCount(),
                    ],
                ]);
                $plan->syncStripe($price->id);
                $this->plans->save($plan);
            }
            ++$created;
        }
        $io->writeln(sprintf('  %d ya estaban, %d creados, %d omitidos o con problemas.', $ok, $created, $failed));
        if ($failed > 0 && !$overwrite) {
            $io->warning(
                'Hay planes con un stripe_price_id que no existe en esta cuenta. Suele pasar al '
                ."apuntar la clave de test a una base de datos con datos de producción.\n"
                .'Si de verdad quieres regenerarlos aquí, repite con --overwrite-ids.'
            );
        }

        // --- descuentos → Coupons -------------------------------------------
        $io->section('Descuentos');
        $ok = $created = 0;

        foreach ($this->allDiscounts() as $discount) {
            $couponId = $discount->getStripeCouponId();
            if ($couponId !== null && $this->exists(fn () => $stripe->coupons->retrieve($couponId))) {
                ++$ok;
                continue;
            }

            $payload = [
                'name'     => mb_substr($discount->getName(), 0, 40),
                'duration' => $discount->getDuration()->value,
            ];
            // Los Coupons sí aceptan id propio, así que se conserva el heredado.
            if ($couponId !== null) {
                $payload['id'] = $couponId;
            }
            if ($discount->getPercentOff() !== null) {
                $payload['percent_off'] = $discount->getPercentOff();
            } else {
                $payload['amount_off'] = $discount->getAmountOffCents();
                $payload['currency']   = $discount->getCurrency();
            }
            if ($discount->getDurationInMonths() !== null) {
                $payload['duration_in_months'] = $discount->getDurationInMonths();
            }

            $io->writeln(sprintf('  <comment>CREAR</comment>   %-28s %s',
                $couponId ?? '(nuevo)',
                $discount->getPercentOff() ? $discount->getPercentOff().'%' : $discount->getAmountOffCents() / 100 .'€'));

            if (!$dryRun) {
                $coupon = $stripe->coupons->create($payload);
                $discount->syncStripe($coupon->id);
                $this->discounts->save($discount);
            }
            ++$created;
        }
        $io->writeln(sprintf('  %d ya estaban, %d creados.', $ok, $created));

        $io->newLine();
        $io->success('Sincronización terminada.');

        return Command::SUCCESS;
    }

    /** Stripe devuelve 404 como excepción; aquí sólo interesa si está o no. */
    private function exists(callable $retrieve): bool
    {
        try {
            $retrieve();

            return true;
        } catch (InvalidRequestException) {
            return false;
        }
    }

    /** @return BillingProduct[] */
    private function allProducts(): array
    {
        return $this->em->getRepository(BillingProduct::class)->findBy(['isActive' => true]);
    }

    /** @return BillingPlan[] */
    private function allPlans(): array
    {
        return $this->em->getRepository(BillingPlan::class)->findBy(['isActive' => true]);
    }

    /** @return Discount[] */
    private function allDiscounts(): array
    {
        return $this->em->getRepository(Discount::class)->findBy(['isActive' => true]);
    }
}
