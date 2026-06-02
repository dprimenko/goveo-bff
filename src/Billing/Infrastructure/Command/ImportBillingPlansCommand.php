<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Command;

use App\Billing\Domain\BillingInterval;
use App\Billing\Domain\BillingPlan;
use App\Billing\Domain\BillingPlanRepository;
use App\Billing\Domain\BillingProductRepository;
use App\Shared\Infrastructure\Firebase\FirestoreClientFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Migrates Firestore `plans` collection → `billing_plans` table.
 *
 * Must run AFTER goveo:migrate:billing-products (plans reference a product/rate).
 *
 * Removed fields (no longer used): minGoveoProducts, maxGoveoProducts, maxServices.
 *
 * Usage:
 *   docker compose exec php php bin/console goveo:migrate:billing-plans
 *   docker compose exec php php bin/console goveo:migrate:billing-plans --dry-run
 */
#[AsCommand(
    name: 'goveo:migrate:billing-plans',
    description: 'Migrates Firestore `plans` collection to billing_plans table. Run after goveo:migrate:billing-products.',
)]
final class ImportBillingPlansCommand extends Command
{
    private const NS = '7e4d3c2a-1b5f-4e8d-9a6c-0f2e1d3b5a7c';

    public function __construct(
        private readonly FirestoreClientFactory $firestoreFactory,
        private readonly BillingPlanRepository $planRepository,
        private readonly BillingProductRepository $productRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would be imported without writing to DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Migrating Firestore `plans` → billing_plans');

        if ($dryRun) {
            $io->note('DRY RUN — no data will be written.');
        }

        $database   = $this->firestoreFactory->create();
        $collection = $database->collection('plans');
        $documents  = $collection->documents();

        $ns      = Uuid::fromString(self::NS);
        $created = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($documents as $doc) {
            if (!$doc->exists()) {
                continue;
            }

            $firestoreId  = $doc->id();
            $data         = $doc->data();
            $internalUuid = Uuid::v5($ns, $firestoreId)->toRfc4122();

            // Check idempotency by Stripe Price ID (Firestore plan ID may already be a Stripe Price ID)
            // For plans created locally (not yet in Stripe), stripePriceId will be null initially.
            $existing = $this->planRepository->findById($internalUuid);
            if ($existing !== null) {
                $io->writeln(sprintf('  <comment>SKIP</comment>  %s — already exists', $data['name'] ?? $firestoreId));
                ++$skipped;
                continue;
            }

            // Resolve billing_product_id from the Stripe Product ID stored in `product` field
            $stripeProductId  = $data['product'] ?? null;
            $billingProductId = null;

            if ($stripeProductId !== null) {
                $billingProduct   = $this->productRepository->findByStripeProductId($stripeProductId);
                $billingProductId = $billingProduct?->getId();
            }

            if ($billingProductId === null) {
                $io->writeln(sprintf(
                    '  <error>ERROR</error>  %s — billing product not found for stripe_product_id: %s (run goveo:migrate:billing-products first)',
                    $data['name'] ?? $firestoreId,
                    $stripeProductId ?? 'null',
                ));
                ++$errors;
                continue;
            }

            // amount: Firestore stores as float (e.g. 29.9). Convert to integer cents.
            $amountCents = (int) round((float) ($data['amount'] ?? 0) * 100);
            $currency    = strtolower((string) ($data['currency'] ?? 'eur'));

            // interval: "month" or "year"
            $interval = match ($data['interval'] ?? 'month') {
                'year'  => BillingInterval::Year,
                default => BillingInterval::Month,
            };

            // intervalCount: 1 if not set, or the value from Firestore
            $intervalCount = (int) ($data['intervalCount'] ?? 1);
            if ($intervalCount < 1) {
                $intervalCount = 1;
            }

            $commissionPercent = (int) ($data['commission'] ?? 0);

            $plan = new BillingPlan(
                id: $internalUuid,
                billingProductId: $billingProductId,
                name: $data['name'] ?? '',
                amountCents: $amountCents,
                currency: $currency,
                interval: $interval,
                intervalCount: $intervalCount,
                commissionPercent: $commissionPercent,
                isVisible: true,  // default visible; adjust manually for hidden plans
                isActive: true,
                stripePriceId: null, // sync with Stripe separately
            );

            $io->writeln(sprintf(
                '  <info>IMPORT</info> %s — %s %s/%s×%d (commission: %d%%)',
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

        $io->success(sprintf(
            'Done. Imported: %d | Skipped: %d | Errors: %d',
            $created,
            $skipped,
            $errors,
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
