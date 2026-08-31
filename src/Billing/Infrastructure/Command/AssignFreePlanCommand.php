<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Command;

use App\Billing\Domain\BillingMode;
use App\Billing\Domain\BillingPlan;
use App\Billing\Domain\BillingPlanRepository;
use App\Billing\Domain\BusinessSubscription;
use App\Billing\Domain\BusinessSubscriptionRepository;
use App\Billing\Domain\SubscriptionStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Pone a la tarifa FREE todos los negocios que no tengan suscripción.
 *
 * Los 448 negocios importados venían del sistema antiguo, donde el estado de
 * pago vivía en `stores.paymentData` de Firestore. Ese dato está desincronizado
 * y **no hay nadie pagando de verdad**, así que arrancan todos en FREE en vez de
 * arrastrar suscripciones que no existen.
 *
 * FREE no toca Stripe: no hay nada que cobrar, así que no hay ni cliente ni
 * suscripción allí. El periodo se deja abierto (`current_period_end` nulo)
 * porque una tarifa gratuita no vence.
 *
 * Idempotente: sólo crea suscripción a quien no tenga ninguna.
 */
#[AsCommand(
    name: 'goveo:billing:assign-free-plan',
    description: 'Asigna la tarifa FREE a los negocios que no tienen suscripción.',
)]
final class AssignFreePlanCommand extends Command
{
    public function __construct(
        private readonly BillingPlanRepository $plans,
        private readonly BusinessSubscriptionRepository $subscriptions,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Muestra sin escribir');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $free = $this->findFreePlan();
        if ($free === null) {
            $io->error('No hay ninguna tarifa gratuita activa. Ejecuta goveo:billing:seed-plans-2026 primero.');

            return Command::FAILURE;
        }

        $io->title(sprintf('Asignando «%s» a los negocios sin suscripción', $free->getName()));
        if ($dryRun) {
            $io->note('DRY RUN');
        }

        $businessIds = $this->connection->fetchFirstColumn(<<<'SQL'
            SELECT b.id
            FROM business b
            LEFT JOIN business_subscriptions s ON s.business_id = b.id
            WHERE b.deleted_at IS NULL AND s.id IS NULL
            ORDER BY b.created_at
        SQL);

        $io->writeln(sprintf('  Negocios sin suscripción: <info>%d</info>', count($businessIds)));

        if ($dryRun || $businessIds === []) {
            return Command::SUCCESS;
        }

        $progress = $io->createProgressBar(count($businessIds));
        foreach ($businessIds as $businessId) {
            $this->subscriptions->save(new BusinessSubscription(
                id:            Uuid::v4()->toRfc4122(),
                businessId:    $businessId,
                billingPlanId: $free->getId(),
                status:        SubscriptionStatus::Active,
                // Una tarifa gratuita no vence: dejar el periodo abierto evita
                // que un proceso de renovación se invente un cobro.
                currentPeriodStart: new \DateTimeImmutable(),
                currentPeriodEnd:   null,
            ));
            $progress->advance();
        }
        $progress->finish();
        $io->newLine(2);
        $io->success(sprintf('%d negocios en FREE.', count($businessIds)));

        return Command::SUCCESS;
    }

    private function findFreePlan(): ?BillingPlan
    {
        foreach ($this->plans->findVisible() as $plan) {
            if ($plan->getMode() === BillingMode::Free) {
                return $plan;
            }
        }

        return null;
    }
}
