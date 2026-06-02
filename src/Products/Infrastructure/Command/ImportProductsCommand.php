<?php

declare(strict_types=1);

namespace App\Products\Infrastructure\Command;

use App\Products\Domain\ContentFormat;
use App\Products\Domain\Product;
use App\Products\Domain\ProductRepository;
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
 * Migrates Firestore `geoproducts` collection → `products` table.
 *
 * Field mapping:
 *   storeId      → business_id  (UUID v5 from Firestore storeId)
 *   categoryId   → category_id  (UUID v5 from Firestore categoryId)
 *   subCategoryId → subcategory_id (UUID v5 from Firestore subCategoryId)
 *   images       → images jsonb (array of {url, order})
 *   price        → price_amount (cents) + price_currency (default EUR)
 *
 * Usage:
 *   docker compose exec php php bin/console goveo:migrate:products
 *   docker compose exec php php bin/console goveo:migrate:products --dry-run
 *   docker compose exec php php bin/console goveo:migrate:products --limit=50
 */
#[AsCommand(
    name: 'goveo:migrate:products',
    description: 'Migrates Firestore `geoproducts` collection to products table.',
)]
final class ImportProductsCommand extends Command
{
    private const NS = '7e4d3c2a-1b5f-4e8d-9a6c-0f2e1d3b5a7c';

    public function __construct(
        private readonly FirestoreClientFactory $firestoreFactory,
        private readonly ProductRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would be imported without writing to DB')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max number of documents to import (useful for testing)', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit  = (int) $input->getOption('limit');

        $io->title('Migrating Firestore `geoproducts` → products');

        if ($dryRun) {
            $io->note('DRY RUN — no data will be written.');
        }

        $database   = $this->firestoreFactory->create();
        $collection = $database->collection('geoproducts');
        $documents  = $collection->documents();

        $ns      = Uuid::fromString(self::NS);
        $created = 0;
        $skipped = 0;
        $errors  = 0;
        $count   = 0;
        /** @var array<string, int> $slugMap "businessId::baseSlug" → use count */
        $slugMap = [];

        foreach ($documents as $doc) {
            if (!$doc->exists()) {
                continue;
            }

            if ($limit > 0 && $count >= $limit) {
                $io->note(sprintf('Limit of %d reached.', $limit));
                break;
            }

            $firestoreId  = $doc->id();
            $data         = $doc->data();
            $internalUuid = Uuid::v5($ns, $firestoreId)->toRfc4122();

            $existing = $this->repository->findById($internalUuid);
            if ($existing !== null) {
                ++$skipped;
                continue;
            }

            // Resolve related UUIDs deterministically from Firestore reference IDs
            $businessId    = isset($data['storeId'])
                ? Uuid::v5($ns, (string) $data['storeId'])->toRfc4122()
                : null;
            $categoryId    = isset($data['categoryId']) && $data['categoryId'] !== ''
                ? Uuid::v5($ns, (string) $data['categoryId'])->toRfc4122()
                : null;
            $subcategoryId = isset($data['subCategoryId']) && $data['subCategoryId'] !== ''
                ? Uuid::v5($ns, (string) $data['subCategoryId'])->toRfc4122()
                : null;

            if ($businessId === null) {
                $io->writeln(sprintf('  <comment>SKIP</comment>  %s — no storeId', $firestoreId));
                ++$skipped;
                continue;
            }

            // images: Firestore array of URLs → [{url, order}] jsonb
            $images = null;
            if (!empty($data['images'])) {
                $images = array_values(array_map(
                    static function ($url, int $idx): array {
                        // Firestore may store images as strings or as {url, ...} maps
                        $resolvedUrl = is_array($url) ? (string) ($url['url'] ?? '') : (string) $url;
                        return ['url' => $resolvedUrl, 'order' => $idx + 1];
                    },
                    (array) $data['images'],
                    array_keys((array) $data['images']),
                ));
            }

            // price: Firestore float → integer cents
            // currency in Firestore is a {symbol, symbolPlace} map, not an ISO code — default EUR
            $priceAmount   = null;
            $priceCurrency = null;
            if (isset($data['price']) && is_numeric($data['price']) && $data['price'] > 0) {
                $priceAmount   = (int) round((float) $data['price'] * 100);
                $rawCurrency   = $data['currency'] ?? null;
                $priceCurrency = is_string($rawCurrency) && $rawCurrency !== '' ? strtoupper($rawCurrency) : 'EUR';
            }

            // slug: use existing slug, or build from title, or fall back to Firestore ID (unique)
            $rawSlug  = isset($data['slug']) && $data['slug'] !== '' ? (string) $data['slug'] : null;
            $baseSlug = $rawSlug ?? $this->slugify((string) ($data['title'] ?? ''));
            if ($baseSlug === '') {
                $baseSlug = $firestoreId;
            }

            // Deduplicate slugs within the same business (in-memory counter)
            $slugKey = $businessId . '::' . $baseSlug;
            if (isset($slugMap[$slugKey])) {
                $slugMap[$slugKey]++;
                $slug = $baseSlug . '-' . $slugMap[$slugKey];
            } else {
                $slugMap[$slugKey] = 1;
                $slug = $baseSlug;
            }

            // createdAt from Firestore Timestamp
            $createdAt = null;
            if (isset($data['createdAt'])) {
                $ts        = $data['createdAt'];
                $createdAt = $ts instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($ts)
                    : new \DateTimeImmutable();
            }

            $product = new Product(
                id: $internalUuid,
                businessId: $businessId,
                title: (string) ($data['title'] ?? ''),
                slug: $slug,
                categoryId: $categoryId,
                subcategoryId: $subcategoryId,
                description: isset($data['description']) ? (string) $data['description'] : null,
                descriptionFormat: ContentFormat::Plain,
                images: $images,
                priceAmount: $priceAmount,
                priceCurrency: $priceCurrency,
                createdAt: $createdAt,
            );

            $io->writeln(sprintf(
                '  <info>IMPORT</info> %s (slug: %s, price: %s)',
                $product->getTitle(),
                $product->getSlug(),
                $product->getFormattedPrice() ?? 'free/on-request',
            ));

            if (!$dryRun) {
                $this->repository->save($product);

                // Clear Doctrine Unit of Work every 200 products to prevent memory growth
                if ($created > 0 && $created % 200 === 0) {
                    $this->em->clear();
                }
            }

            ++$created;
            ++$count;
        }

        $io->success(sprintf(
            'Done. Imported: %d | Skipped: %d | Errors: %d',
            $created,
            $skipped,
            $errors,
        ));

        return Command::SUCCESS;
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text) ?? $text;
        $text = preg_replace('/[\s_]+/', '-', $text) ?? $text;
        $text = preg_replace('/-+/', '-', $text) ?? $text;

        return trim($text, '-');
    }
}
