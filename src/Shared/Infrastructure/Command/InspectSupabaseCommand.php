<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Command;

use App\Shared\Infrastructure\Migration\SupabaseConnectionFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Inspect the Supabase schema before running migrations.
 *
 * Usage:
 *   # List all tables
 *   docker compose exec php php bin/console goveo:supabase:inspect
 *
 *   # Show columns + sample rows for a specific table
 *   docker compose exec php php bin/console goveo:supabase:inspect rates
 *   docker compose exec php php bin/console goveo:supabase:inspect plans
 *   docker compose exec php php bin/console goveo:supabase:inspect geoproducts
 */
#[AsCommand(
    name: 'goveo:supabase:inspect',
    description: 'Lists Supabase tables or shows columns + sample rows for a given table.',
)]
final class InspectSupabaseCommand extends Command
{
    public function __construct(
        private readonly SupabaseConnectionFactory $factory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('table', InputArgument::OPTIONAL, 'Table name to inspect (omit to list all tables)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $conn  = $this->factory->create();
        $table = $input->getArgument('table');

        if ($table === null) {
            return $this->listTables($io, $conn);
        }

        return $this->inspectTable($io, $conn, $table);
    }

    private function listTables(SymfonyStyle $io, \Doctrine\DBAL\Connection $conn): int
    {
        $io->title('Supabase — all tables (public schema)');

        $rows = $conn->fetchAllAssociative("
            SELECT table_name, pg_size_pretty(pg_total_relation_size(quote_ident(table_name))) AS size
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_type = 'BASE TABLE'
            ORDER BY table_name
        ");

        if (empty($rows)) {
            $io->warning('No tables found in public schema.');
            return Command::SUCCESS;
        }

        $io->table(['Table', 'Size'], array_map(
            static fn (array $r): array => [$r['table_name'], $r['size']],
            $rows,
        ));

        $io->note(sprintf(
            'Run "goveo:supabase:inspect <table>" to see columns and sample rows.',
        ));

        return Command::SUCCESS;
    }

    private function inspectTable(SymfonyStyle $io, \Doctrine\DBAL\Connection $conn, string $table): int
    {
        $io->title(sprintf('Supabase — table: %s', $table));

        // Columns
        $columns = $conn->fetchAllAssociative("
            SELECT column_name, data_type, is_nullable, column_default
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = :table
            ORDER BY ordinal_position
        ", ['table' => $table]);

        if (empty($columns)) {
            $io->error(sprintf('Table "%s" not found or has no columns.', $table));
            return Command::FAILURE;
        }

        $io->section('Columns');
        $io->table(
            ['Column', 'Type', 'Nullable', 'Default'],
            array_map(static fn (array $c): array => [
                $c['column_name'],
                $c['data_type'],
                $c['is_nullable'],
                $c['column_default'] ?? '',
            ], $columns),
        );

        // Row count
        $count = $conn->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
        $io->writeln(sprintf('<info>Total rows:</info> %s', number_format((int) $count)));

        // Sample rows (first 3)
        $sample = $conn->fetchAllAssociative(sprintf('SELECT * FROM %s LIMIT 3', $table));

        if (!empty($sample)) {
            $io->section('Sample rows (first 3)');
            foreach ($sample as $idx => $row) {
                $io->writeln(sprintf('<comment>Row %d:</comment>', $idx + 1));
                foreach ($row as $col => $val) {
                    $display = is_string($val) && mb_strlen($val) > 120
                        ? mb_substr($val, 0, 120) . '…'
                        : (string) ($val ?? 'NULL');
                    $io->writeln(sprintf('  <info>%s</info>: %s', $col, $display));
                }
                $io->newLine();
            }
        }

        return Command::SUCCESS;
    }
}
