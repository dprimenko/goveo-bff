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
 * IDs are slug strings (e.g. "place") converted to deterministic UUID v5.
 * The original Supabase text ID is stored as `slug` (e.g. "place", "events").
 * The `partner` field is stored as a plain text slug (not a UUID FK).
 *
 * Re-running this command is safe: existing rows get their `slug` backfilled.
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
            $slug = $row['id'];           // original Supabase text ID, e.g. "place"
            $id   = $this->toUuid($slug); // deterministic UUID v5

            $hasSlug = (bool) $tgt->fetchOne('SELECT 1 FROM categories WHERE id = ? AND slug IS NOT NULL', [$id]);
            $exists  = (bool) $tgt->fetchOne('SELECT 1 FROM categories WHERE id = ?', [$id]);

            if ($exists && $hasSlug) {
                $io->writeln(sprintf('  <comment>SKIP</comment>  %s', $slug));
                ++$skipped;
                continue;
            }

            $action = $exists ? 'UPDATE' : 'INSERT';
            $io->writeln(sprintf('  <info>%s</info> %s → %s  [%s]', $action, $slug, $id, $row['name'] ?? ''));

            // influencer | business | both — who can post video under this category.
            $mode = \App\Categories\Domain\Category::modeForSlug($slug);

            if (!$dryRun) {
                try {
                    $tgt->executeStatement(
                        'INSERT INTO categories (id, slug, name, image, "order", partner, mode, created_at, updated_at, deleted_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                         ON CONFLICT (id) DO UPDATE SET slug = EXCLUDED.slug, mode = EXCLUDED.mode',
                        [
                            $id,
                            $slug,
                            $row['name'],
                            $row['image'],
                            $row['order'] !== null ? (int) $row['order'] : null,
                            $row['partner'],
                            $mode,
                            $this->ts($row['created_at']),
                            $this->ts($row['updated_at']),
                            $this->ts($row['deleted_at'] ?? null),
                        ]
                    );
                } catch (\Throwable $e) {
                    $io->warning(sprintf('  ERROR  %s — %s', $slug, $e->getMessage()));
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
