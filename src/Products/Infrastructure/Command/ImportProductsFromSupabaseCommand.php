<?php

declare(strict_types=1);

namespace App\Products\Infrastructure\Command;

use App\Products\Domain\ContentFormat;
use App\Products\Domain\Product;
use App\Products\Domain\ProductRepository;
use App\Shared\Infrastructure\Migration\SupabaseConnectionFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Migrates Supabase `geoproducts` table → `products`.
 *
 * Usage:
 *   docker compose exec php php bin/console goveo:migrate:supabase:products
 *   docker compose exec php php bin/console goveo:migrate:supabase:products --dry-run
 *   docker compose exec php php bin/console goveo:migrate:supabase:products --limit=10
 */
#[AsCommand(
    name: 'goveo:migrate:supabase:products',
    description: 'Migrates Supabase `geoproducts` table to products.',
)]
final class ImportProductsFromSupabaseCommand extends Command
{
    private const NS = '7e4d3c2a-1b5f-4e8d-9a6c-0f2e1d3b5a7c';

    public function __construct(
        private readonly SupabaseConnectionFactory $supabase,
        private readonly ProductRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print without writing to DB')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows to process (for testing)', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit  = (int) $input->getOption('limit');

        $io->title('Supabase geoproducts → products');
        if ($dryRun) {
            $io->note('DRY RUN');
        }

        $conn = $this->supabase->create();

        $sql  = 'SELECT * FROM geoproducts ORDER BY "createdAt" ASC NULLS LAST';
        if ($limit > 0) {
            $sql .= sprintf(' LIMIT %d', $limit);
        }

        $rows = $conn->fetchAllAssociative($sql);

        if (empty($rows)) {
            $io->warning('No rows found in `geoproducts` table.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Found <info>%d</info> rows.', count($rows)));

        $ns      = Uuid::fromString(self::NS);
        $created = $skipped = $errors = 0;

        foreach ($rows as $row) {
            $firestoreId  = $this->col($row, 'id');
            $internalUuid = Uuid::v5($ns, (string) $firestoreId)->toRfc4122();

            if ($this->repository->findById($internalUuid) !== null) {
                ++$skipped;
                continue;
            }

            $rawStoreId = $this->col($row, 'storeId');
            if ($rawStoreId === null || $rawStoreId === '') {
                $io->writeln(sprintf('  <comment>SKIP</comment>  %s — no storeId', $firestoreId));
                ++$errors;
                continue;
            }

            $businessId    = Uuid::v5($ns, (string) $rawStoreId)->toRfc4122();
            $rawCategoryId = $this->col($row, 'categoryId');
            $categoryId    = ($rawCategoryId !== null && $rawCategoryId !== '')
                ? Uuid::v5($ns, (string) $rawCategoryId)->toRfc4122()
                : null;
            $rawSubcatId   = $this->col($row, 'subCategoryId');
            $subcategoryId = ($rawSubcatId !== null && $rawSubcatId !== '')
                ? Uuid::v5($ns, (string) $rawSubcatId)->toRfc4122()
                : null;

            // images: Supabase may store as jsonb array of strings or objects
            $images    = null;
            $rawImages = $this->col($row, 'images');
            if ($rawImages !== null) {
                $parsed = is_string($rawImages) ? json_decode($rawImages, true) : $rawImages;
                if (is_array($parsed) && !empty($parsed)) {
                    $images = array_values(array_map(
                        static function (mixed $item, int $idx): array {
                            $url = is_string($item) ? $item : ($item['url'] ?? '');

                            return ['url' => $url, 'order' => $idx + 1];
                        },
                        $parsed,
                        array_keys($parsed),
                    ));
                }
            }

            // price
            $priceAmount   = null;
            $priceCurrency = null;
            $rawPrice      = $this->col($row, 'price');
            if ($rawPrice !== null && (float) $rawPrice > 0) {
                $priceAmount   = (int) round((float) $rawPrice * 100);
                $priceCurrency = strtoupper((string) ($this->col($row, 'currency') ?? 'EUR'));
            }

            // slug
            $slug = $this->col($row, 'slug');
            if ($slug === null || $slug === '') {
                $slug = $this->slugify((string) ($this->col($row, 'title') ?? $firestoreId));
            }

            // createdAt
            $rawCreated = $this->col($row, 'createdAt');
            $createdAt  = null;
            if ($rawCreated !== null) {
                try {
                    $createdAt = new \DateTimeImmutable((string) $rawCreated);
                } catch (\Throwable) {
                    $createdAt = null;
                }
            }

            $product = new Product(
                id: $internalUuid,
                businessId: $businessId,
                title: (string) ($this->col($row, 'title') ?? ''),
                slug: $slug,
                categoryId: $categoryId,
                subcategoryId: $subcategoryId,
                description: $this->col($row, 'description') !== null
                    ? (string) $this->col($row, 'description')
                    : null,
                descriptionFormat: ContentFormat::Plain,
                images: $images,
                priceAmount: $priceAmount,
                priceCurrency: $priceCurrency,
                createdAt: $createdAt,
            );

            $io->writeln(sprintf(
                '  <info>IMPORT</info> %-40s  slug: %s  price: %s',
                mb_substr($product->getTitle(), 0, 40),
                $product->getSlug(),
                $product->getFormattedPrice() ?? '—',
            ));

            if (!$dryRun) {
                $this->repository->save($product);
            }

            ++$created;
        }

        $io->success(sprintf('Imported: %d | Skipped: %d | No storeId: %d', $created, $skipped, $errors));

        return Command::SUCCESS;
    }

    private function col(array $row, string $name): mixed
    {
        return $row[$name]
            ?? $row[$this->toSnake($name)]
            ?? $row[$this->toCamel($name)]
            ?? $row[strtolower($name)]
            ?? null;
    }

    private function toSnake(string $s): string
    {
        return strtolower(preg_replace('/[A-Z]/', '_$0', $s) ?? $s);
    }

    private function toCamel(string $s): string
    {
        return lcfirst(str_replace('_', '', ucwords($s, '_')));
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text) ?? $text;
        $text = preg_replace('/[\s_]+/', '-', $text) ?? $text;
        $text = preg_replace('/-+/', '-', $text) ?? $text;

        return trim($text, '-') ?: 'product';
    }
}
