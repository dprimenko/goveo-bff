<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Command;

use App\Billing\Domain\BillingInterval;
use App\Billing\Domain\BillingPlan;
use App\Billing\Domain\BillingPlanRepository;
use App\Billing\Domain\BillingProductRepository;
use App\Shared\Infrastructure\Migration\SupabaseConnectionFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Migrates Supabase `plans` table → `billing_plans`.
 * Must run AFTER goveo:migrate:supabase:billing-products.
 *
 * Usage:
 *   docker compose exec php php bin/console goveo:migrate:supabase:billing-plans
 *   docker compose exec php php bin/console goveo:migrate:supabase:billing-plans --dry-run
 */
#[AsCommand(
    name: 'goveo:migrate:supabase:billing-plans',
    description: 'Migrates Supabase `plans` table to billing_plans. Run after goveo:migrate:supabase:billing-products.',
)]
final class ImportBillingPlansFromSupabaseCommand extends Command
{
    private const NS = '7e4d3c2a-1b5f-4e8d-9a6c-0f2e1d3b5a7c';

    public function __construct(
        private readonly SupabaseConnectionFactory $supabase,
        private readonly BillingPlanRepository $planRepository,
        private readonly BillingProductRepository $productRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print without writing to DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Supabase plans → billing_plans');
        if ($dryRun) {
            $io->note('DRY RUN');
        }

        $conn = $this->supabase->create();
        $rows = $conn->fetchAllAssociative('SELECT * FROM plans ORDER BY amount ASC');

        if (empty($rows)) {
            $io->warning('No rows found in `plans` table.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Found <info>%d</info> rows.', count($rows)));

        $ns      = Uuid::fromString(self::NS);
        $created = $skipped = $errors = 0;

        foreach ($rows as $row) {
            $firestoreId  = $this->col($row, 'id');
            $internalUuid = Uuid::v5($ns, $firestoreId)->toRfc4122();

            if ($this->planRepository->findById($internalUuid) !== null) {
                $io->writeln(sprintf('  <comment>SKIP</comment>  %s — already exists', $this->col($row, 'name')));
                ++$skipped;
                continue;
            }

            // Resolve billing_product_id via Stripe Product ID
            $stripeProductId  = $this->col($row, 'product');
            $billingProductId = null;

            if ($stripeProductId !== null) {
                $billingProduct   = $this->productRepository->findByStripeProductId((string) $stripeProductId);
                $billingProductId = $billingProduct?->getId();
            }

            if ($billingProductId === null) {
                $io->writeln(sprintf(
                    '  <error>ERROR</error>  %s — billing product not found (product: %s)',
                    $this->col($row, 'name') ?? $firestoreId,
                    $stripeProductId ?? 'null',
                ));
                ++$errors;
                continue;
            }

            $amountCents       = (int) round((float) ($this->col($row, 'amount') ?? 0) * 100);
            $currency          = strtolower((string) ($this->col($row, 'currency') ?? 'eur'));
            $intervalCount     = max(1, (int) ($this->col($row, 'intervalCount') ?? $this->col($row, 'interval_count') ?? 1));
            $commissionPercent = (int) ($this->col($row, 'commission') ?? 0);

            $interval = match ($this->col($row, 'interval') ?? 'month') {
                'year'  => BillingInterval::Year,
                default => BillingInterval::Month,
            };

            $plan = new BillingPlan(
                id: $internalUuid,
                billingProductId: $billingProductId,
                name: (string) ($this->col($row, 'name') ?? ''),
                amountCents: $amountCents,
                currency: $currency,
                interval: $interval,
                intervalCount: $intervalCount,
                commissionPercent: $commissionPercent,
                isVisible: true,
                isActive: true,
            );

            $io->writeln(sprintf(
                '  <info>IMPORT</info> %-40s  %s %s/%s×%d  commission: %d%%',
                $plan->getName(),
                number_format($plan->getAmountDecimal(), 2),
                strtoupper($currency),
                $interval->value,
                $intervalCount,
                $commissionPercent,
            ));

            if (!$dryRun) {
                $this->planRepository->save($plan);
            }

            ++$created;
        }

        $io->success(sprintf('Imported: %d | Skipped: %d | Errors: %d', $created, $skipped, $errors));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function col(array $row, string $name): mixed
    {
        return $row[$name] ?? $row[$this->toSnake($name)] ?? $row[$this->toCamel($name)] ?? null;
    }

    private function toSnake(string $s): string
    {
        return strtolower(preg_replace('/[A-Z]/', '_$0', $s) ?? $s);
    }

    private function toCamel(string $s): string
    {
        return lcfirst(str_replace('_', '', ucwords($s, '_')));
    }
}
