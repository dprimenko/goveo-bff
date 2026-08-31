<?php

declare(strict_types=1);

namespace App\Products\Infrastructure\Command;

use App\Products\Domain\ProductSubcategory;
use App\Products\Domain\ProductSubcategoryRepository;
use App\Shared\Infrastructure\Firebase\FirestoreClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Migrates the per-store `subCategories` array (Firestore `stores` collection)
 * → `product_subcategories` table.
 *
 * In Firestore, each store document holds:
 *   subCategories: [ { custom: bool, id: "8za5kDwCS", name: "Jamón Pieza entera" }, ... ]
 *
 * The subcategory `id` is the SAME reference that products carry in
 * `subCategoryId`, so we derive the row id with the SAME UUID v5 namespace used
 * by ImportProductsCommand — that way `product.subcategory_id` matches
 * `product_subcategories.id`. The business id is derived from the store doc id
 * (== products' storeId).
 *
 * Usage:
 *   php bin/console goveo:migrate:subcategories
 *   php bin/console goveo:migrate:subcategories --dry-run
 *   php bin/console goveo:migrate:subcategories --store=2vyvumaqnCCVqE5xBoaE
 */
#[AsCommand(
    name: 'goveo:migrate:subcategories',
    description: 'Migrates per-store subCategories (Firestore `stores`) to product_subcategories.',
)]
final class ImportStoreSubcategoriesCommand extends Command
{
    // Same namespace as ImportProductsCommand so ids line up with products.
    private const NS = '7e4d3c2a-1b5f-4e8d-9a6c-0f2e1d3b5a7c';

    public function __construct(
        private readonly FirestoreClientFactory $firestoreFactory,
        private readonly ProductSubcategoryRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would be imported without writing to DB')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Import a single store by Firestore document id', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $dryRun  = (bool) $input->getOption('dry-run');
        $storeId = $input->getOption('store');

        $io->title('Migrating Firestore `stores.subCategories` → product_subcategories');

        if ($dryRun) {
            $io->note('DRY RUN — no data will be written.');
        }

        $database   = $this->firestoreFactory->create();
        $collection = $database->collection('stores');
        $documents  = $storeId !== null
            ? [$collection->document((string) $storeId)->snapshot()]
            : $collection->documents();

        $ns      = Uuid::fromString(self::NS);
        $created = $skipped = 0;

        foreach ($documents as $doc) {
            if (!$doc->exists()) {
                continue;
            }

            $storeDocId = $doc->id();
            $data       = $doc->data();
            $subs       = $data['subCategories'] ?? null;

            if (!is_array($subs) || empty($subs)) {
                continue;
            }

            $businessId = Uuid::v5($ns, $storeDocId)->toRfc4122();

            foreach (array_values($subs) as $index => $sub) {
                if (!is_array($sub) || empty($sub['id']) || empty($sub['name'])) {
                    continue;
                }

                $subId = Uuid::v5($ns, (string) $sub['id'])->toRfc4122();

                if ($this->repository->findById($subId) !== null) {
                    ++$skipped;
                    continue;
                }

                $subcategory = new ProductSubcategory(
                    id: $subId,
                    businessId: $businessId,
                    name: (string) $sub['name'],
                    description: null,
                    sortOrder: $index,
                );

                $io->writeln(sprintf(
                    '  <info>IMPORT</info> %-30s  store: %s',
                    mb_substr((string) $sub['name'], 0, 30),
                    $storeDocId,
                ));

                if (!$dryRun) {
                    $this->em->persist($subcategory);
                }

                ++$created;
            }

            if (!$dryRun && $created > 0 && $created % 200 === 0) {
                $this->em->flush();
                $this->em->clear();
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf('Done. Imported: %d | Skipped (existing): %d', $created, $skipped));

        return Command::SUCCESS;
    }
}
