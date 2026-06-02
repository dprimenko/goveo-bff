<?php

declare(strict_types=1);

namespace App\Categories\Infrastructure\Command;

use App\Shared\Infrastructure\Command\AbstractSupabaseMigrationCommand;
use App\Shared\Infrastructure\Migration\SupabaseConnectionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migrates Supabase `categories` → local `categories`.
 *
 * IDs are slug strings (e.g. "accesories") converted to deterministic UUID v5.
 * The `partner` field is stored as a plain text slug (not a UUID FK).
 *
 * Usage:
 *   docker compose exec php php bin/console goveo:migrate:supabase:categories
 *   docker compose exec php php bin/console goveo:migrate:supabase:categories --dry-run
 */
#[AsCommand(
    name: 'goveo:migrate:supabase:categories',
    description: 'Migrates Supabase categories table to local DB.',
)]
final class ImportCategoriesFromSupabaseCommand extends AbstractSupabaseMigrationCommand
{
    public function __construct(
        private readonly SupabaseConnectionFactory $supabase,
        private readonly EntityManagerInterface $em,
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

        $io->title('Supabase categories → categories');
        if ($dryRun) {
            $io->note('DRY RUN');
        }

        $src  = $this->supabase->create();
        $tgt  = $this->em->getConnection();
        $rows = $src->fetchAllAssociative(
            'SELECT id, name, image, "order", partner, created_at, updated_at, deleted_at
             FROM categories ORDER BY created_at ASC'
        );

        if (empty($rows)) {
            $io->warning('No rows found.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Found <info>%d</info> rows.', count($rows)));

        $created = $skipped = $errors = 0;

        foreach ($rows as $row) {
            $id = $this->toUuid($row['id']);

            $exists = (bool) $tgt->fetchOne('SELECT 1 FROM categories WHERE id = ?', [$id]);
            if ($exists) {
                $io->writeln(sprintf('  <comment>SKIP</comment>  %s', $row['id']));
                ++$skipped;
                continue;
            }

            $io->writeln(sprintf('  <info>IMPORT</info> %s → %s  [%s]', $row['id'], $id, $row['name'] ?? ''));

            if (!$dryRun) {
                try {
                    $tgt->executeStatement(
                        'INSERT INTO categories (id, name, image, "order", partner, created_at, updated_at, deleted_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (id) DO NOTHING',
                        [
                            $id,
                            $row['name'],
                            $row['image'],
                            $row['order'] !== null ? (int) $row['order'] : null,
                            $row['partner'],
                            $this->ts($row['created_at']),
                            $this->ts($row['updated_at']),
                            $this->ts($row['deleted_at'] ?? null),
                        ]
                    );
                } catch (\Throwable $e) {
                    $io->warning(sprintf('  ERROR  %s — %s', $row['id'], $e->getMessage()));
                    ++$errors;
                    continue;
                }
            }

            ++$created;
        }

        $io->success(sprintf('Imported: %d | Skipped: %d | Errors: %d', $created, $skipped, $errors));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
