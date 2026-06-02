<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Command;

use App\Billing\Domain\BillingProduct;
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
 * Migrates Supabase `rates` table → `billing_products`.
 *
 * Run goveo:supabase:inspect rates first to confirm column names.
 *
 * Usage:
 *   docker compose exec php php bin/console goveo:migrate:supabase:billing-products
 *   docker compose exec php php bin/console goveo:migrate:supabase:billing-products --dry-run
 */
#[AsCommand(
    name: 'goveo:migrate:supabase:billing-products',
    description: 'Migrates Supabase `rates` table to billing_products.',
)]
final class ImportBillingProductsFromSupabaseCommand extends Command
{
    private const NS = '7e4d3c2a-1b5f-4e8d-9a6c-0f2e1d3b5a7c';

    public function __construct(
        private readonly SupabaseConnectionFactory $supabase,
        private readonly BillingProductRepository $repository,
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

        $io->title('Supabase rates → billing_products');
        if ($dryRun) {
            $io->note('DRY RUN');
        }

        $conn = $this->supabase->create();
        $rows = $conn->fetchAllAssociative('SELECT * FROM rates ORDER BY sort ASC NULLS LAST');

        if (empty($rows)) {
            $io->warning('No rows found in `rates` table.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Found <info>%d</info> rows.', count($rows)));

        $ns      = Uuid::fromString(self::NS);
        $created = $skipped = 0;

        foreach ($rows as $row) {
            // Firestore doc ID = Stripe Product ID = primary key in Supabase rates table
            $stripeProductId = $this->col($row, 'id');
            $internalUuid    = Uuid::v5($ns, $stripeProductId)->toRfc4122();

            if ($this->repository->findByStripeProductId($stripeProductId) !== null) {
                $io->writeln(sprintf('  <comment>SKIP</comment>  %s — already exists', $this->col($row, 'name')));
                ++$skipped;
                continue;
            }

            // description: Firestore array stored as jsonb or text[] in Supabase
            $rawDesc     = $this->col($row, 'description');
            $description = $this->parseJsonArray($rawDesc);

            // metadata.type → types array
            $types    = [];
            $rawMeta  = $this->col($row, 'metadata');
            $metadata = $this->parseJson($rawMeta);
            if (isset($metadata['type']) && $metadata['type'] !== '') {
                $types = [$metadata['type']];
            }

            $product = new BillingProduct(
                id: $internalUuid,
                name: (string) ($this->col($row, 'name') ?? ''),
                stripeProductId: $stripeProductId,
                types: $types,
                description: $description,
                sortOrder: (int) ($this->col($row, 'sort') ?? 0),
                isActive: true,
            );

            $io->writeln(sprintf(
                '  <info>IMPORT</info> %-30s stripe_id: %s  types: [%s]',
                $product->getName(),
                $stripeProductId,
                implode(', ', $types),
            ));

            if (!$dryRun) {
                $this->repository->save($product);
            }

            ++$created;
        }

        $io->success(sprintf('Imported: %d | Skipped: %d', $created, $skipped));

        return Command::SUCCESS;
    }

    /** Tries camelCase then snake_case column name. */
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

    private function parseJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function parseJsonArray(mixed $raw): ?array
    {
        $arr = $this->parseJson($raw);

        return empty($arr) ? null : array_values($arr);
    }
}
