<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Command;

use App\Billing\Domain\BillingProduct;
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
 * Migrates Firestore `rates` collection → `billing_products` table.
 *
 * Firestore document ID IS the Stripe Product ID.
 * UUID is generated deterministically via UUID v5 so re-runs are idempotent.
 *
 * Usage:
 *   docker compose exec php php bin/console goveo:migrate:billing-products
 *   docker compose exec php php bin/console goveo:migrate:billing-products --dry-run
 */
#[AsCommand(
    name: 'goveo:migrate:billing-products',
    description: 'Migrates Firestore `rates` collection to billing_products table.',
)]
final class ImportBillingProductsCommand extends Command
{
    // UUID v5 namespace for goveo Firestore IDs (arbitrary stable UUID)
    private const NS = '7e4d3c2a-1b5f-4e8d-9a6c-0f2e1d3b5a7c';

    public function __construct(
        private readonly FirestoreClientFactory $firestoreFactory,
        private readonly BillingProductRepository $repository,
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

        $io->title('Migrating Firestore `rates` → billing_products');

        if ($dryRun) {
            $io->note('DRY RUN — no data will be written.');
        }

        $database   = $this->firestoreFactory->create();
        $collection = $database->collection('rates');
        $documents  = $collection->documents();

        $created  = 0;
        $skipped  = 0;
        $ns       = Uuid::fromString(self::NS);

        foreach ($documents as $doc) {
            if (!$doc->exists()) {
                continue;
            }

            $firestoreId     = $doc->id(); // This IS the Stripe Product ID
            $data            = $doc->data();
            $internalUuid    = Uuid::v5($ns, $firestoreId)->toRfc4122();

            // Check if already imported (idempotent)
            $existing = $this->repository->findByStripeProductId($firestoreId);
            if ($existing !== null) {
                $io->writeln(sprintf('  <comment>SKIP</comment>  %s (%s) — already exists', $data['name'] ?? $firestoreId, $firestoreId));
                ++$skipped;
                continue;
            }

            // Map `metadata.type` → types array (supports multi-category filter)
            $types = [];
            if (isset($data['metadata']['type']) && $data['metadata']['type'] !== '') {
                $types = [$data['metadata']['type']];
            }

            // description is stored as a Firestore array field {0: "...", 1: "..."}
            $description = null;
            if (!empty($data['description'])) {
                $description = array_values((array) $data['description']);
            }

            $product = new BillingProduct(
                id: $internalUuid,
                name: $data['name'] ?? '',
                stripeProductId: $firestoreId,
                types: $types,
                description: $description,
                sortOrder: (int) ($data['sort'] ?? 0),
                isActive: true,
            );

            $io->writeln(sprintf(
                '  <info>IMPORT</info> %s (stripe_id: %s, types: [%s])',
                $product->getName(),
                $firestoreId,
                implode(', ', $types),
            ));

            if (!$dryRun) {
                $this->repository->save($product);
            }

            ++$created;
        }

        $io->success(sprintf(
            'Done. Imported: %d | Skipped (already existed): %d',
            $created,
            $skipped,
        ));

        return Command::SUCCESS;
    }
}
