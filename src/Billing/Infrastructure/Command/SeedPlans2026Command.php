<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Command;

use App\Billing\Domain\BillingInterval;
use App\Billing\Domain\BillingMode;
use App\Billing\Domain\BillingPlan;
use App\Billing\Domain\BillingPlanRepository;
use App\Billing\Domain\BillingProduct;
use App\Billing\Domain\BillingProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Crea las tarifas del prelanzamiento comercial (TOP 3, PLATINUM, PREMIUM, FREE)
 * y, opcionalmente, jubila las 58 heredadas de Firestore.
 *
 * Un producto por tarifa y un plan por periodicidad, que es como lo modela
 * Stripe: el producto es lo que se vende y los precios son sus variantes.
 *
 * Idempotente: los ids son UUID v5 de un nombre fijo, así que volver a lanzarlo
 * no duplica nada. Después hay que ejecutar `goveo:stripe:sync` para crear los
 * Prices en la cuenta del entorno.
 *
 * ENTERPRISE no entra: es «a medida» y no se contrata solo.
 */
#[AsCommand(
    name: 'goveo:billing:seed-plans-2026',
    description: 'Crea las tarifas TOP 3 / PLATINUM / PREMIUM / FREE y jubila las heredadas.',
)]
final class SeedPlans2026Command extends Command
{
    private const NS = '7e4d3c2a-1b5f-4e8d-9a6c-0f2e1d3b5a7c';

    /**
     * Importes en céntimos, tal y como aparecen en la landing.
     * El semestral es `interval_count = 6` sobre meses, no un intervalo propio.
     */
    private const TARIFFS = [
        [
            'key'   => 'top3',
            'name'  => 'TOP 3',
            'sort'  => 10,
            'blurb' => 'Puestos 1-3 de tu categoría',
            'prices' => ['month' => 18000, 'biannual' => 97200, 'year' => 172800],
        ],
        [
            'key'   => 'platinum',
            'name'  => 'PLATINUM',
            'sort'  => 20,
            'blurb' => 'Puestos 4-15 de tu categoría',
            'prices' => ['month' => 5900, 'biannual' => 31800, 'year' => 63500],
        ],
        [
            'key'   => 'premium',
            'name'  => 'PREMIUM',
            'sort'  => 30,
            'blurb' => 'Puestos 16-40 de tu categoría',
            'prices' => ['month' => 3500, 'biannual' => 18900, 'year' => 33600],
        ],
        [
            'key'    => 'free',
            'name'   => 'FREE',
            'sort'   => 40,
            'blurb'  => 'Ubicación en el mapa',
            // Gratis no tiene periodicidades: una sola tarifa, sin Stripe.
            'prices' => ['month' => 0],
        ],
    ];

    public function __construct(
        private readonly BillingProductRepository $products,
        private readonly BillingPlanRepository $plans,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Muestra sin escribir');
        $this->addOption('retire-legacy', null, InputOption::VALUE_NONE, 'Desactiva las tarifas heredadas');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $ns     = Uuid::fromString(self::NS);

        $io->title('Tarifas 2026');
        if ($dryRun) {
            $io->note('DRY RUN');
        }

        $created = $skipped = 0;

        foreach (self::TARIFFS as $tariff) {
            $productId = Uuid::v5($ns, 'product:2026:'.$tariff['key'])->toRfc4122();

            if ($this->products->findById($productId) === null) {
                $io->writeln(sprintf('  <comment>PRODUCTO</comment> %s', $tariff['name']));
                if (!$dryRun) {
                    $this->products->save(new BillingProduct(
                        id:          $productId,
                        name:        $tariff['name'],
                        description: [$tariff['blurb']],
                        sortOrder:   $tariff['sort'],
                    ));
                }
            }

            foreach ($tariff['prices'] as $period => $amount) {
                $planId = Uuid::v5($ns, sprintf('plan:2026:%s:%s', $tariff['key'], $period))->toRfc4122();

                if ($this->plans->findById($planId) !== null) {
                    ++$skipped;
                    continue;
                }

                [$interval, $count] = match ($period) {
                    'month'    => [BillingInterval::Month, 1],
                    'biannual' => [BillingInterval::Month, 6],
                    'year'     => [BillingInterval::Year, 1],
                };

                $io->writeln(sprintf(
                    '  <info>PLAN</info>     %-10s %-9s %8s €  (%d × %s)',
                    $tariff['name'],
                    $period,
                    number_format($amount / 100, 2, ',', '.'),
                    $count,
                    $interval->value,
                ));

                if (!$dryRun) {
                    $this->plans->save(new BillingPlan(
                        id:               $planId,
                        billingProductId: $productId,
                        // Lo que viaja en los enlaces: `?plan=platinum-anual`.
                        code:             sprintf('%s-%s', $tariff['key'], $this->slug($period)),
                        name:             sprintf('%s · %s', $tariff['name'], $this->label($period)),
                        amountCents:      $amount,
                        currency:         'eur',
                        interval:         $interval,
                        intervalCount:    $count,
                        mode:             $amount === 0 ? BillingMode::Free : BillingMode::Paid,
                        // Se listan solas en la landing: ya no hace falta código.
                        isVisible:        true,
                    ));
                }
                ++$created;
            }
        }

        $io->writeln(sprintf('  %d planes creados, %d ya existían.', $created, $skipped));

        if ($input->getOption('retire-legacy')) {
            $io->section('Jubilando tarifas heredadas');
            $retired = $this->retireLegacy($dryRun);
            $io->writeln(sprintf('  %d tarifas desactivadas.', $retired));
            $io->note('Las suscripciones vivas sobre esas tarifas siguen intactas: sólo dejan de ofrecerse.');
        }

        $io->success('Listo. Ejecuta goveo:stripe:sync para crear los Prices en Stripe.');

        return Command::SUCCESS;
    }

    private function retireLegacy(bool $dryRun): int
    {
        $ids = array_map(
            fn (array $t) => array_map(
                fn (string $p) => Uuid::v5(Uuid::fromString(self::NS), sprintf('plan:2026:%s:%s', $t['key'], $p))->toRfc4122(),
                array_keys($t['prices']),
            ),
            self::TARIFFS,
        );
        $keep = array_merge(...$ids);

        if ($dryRun) {
            return (int) $this->em->createQuery(
                'SELECT COUNT(p) FROM App\Billing\Domain\BillingPlan p WHERE p.isActive = true AND p.id NOT IN (:keep)'
            )->setParameter('keep', $keep)->getSingleScalarResult();
        }

        return (int) $this->em->createQuery(
            'UPDATE App\Billing\Domain\BillingPlan p SET p.isActive = false WHERE p.isActive = true AND p.id NOT IN (:keep)'
        )->setParameter('keep', $keep)->execute();
    }

    /** Trozo del código que va en la URL. */
    private function slug(string $period): string
    {
        return match ($period) {
            'month'    => 'mensual',
            'biannual' => 'semestral',
            'year'     => 'anual',
        };
    }

    private function label(string $period): string
    {
        return match ($period) {
            'month'    => 'Mensual',
            'biannual' => 'Semestral',
            'year'     => 'Anual',
        };
    }
}
