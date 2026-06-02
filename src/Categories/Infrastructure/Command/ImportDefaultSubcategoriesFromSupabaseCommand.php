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
 * Migrates Supabase `default_subcategories` → local `default_subcategories`.
 *
 * IDs and category_ids are slug strings converted to deterministic UUID v5.
 *
 * Usage:
 *   docker compose exec php php bin/console goveo:migrate:supabase:default-subcategories
 */
#[AsCommand(
    name: 'goveo:migrate:supabase:default-subcategories',
    description: 'Migrates Supabase default_subcategories table to local DB.',
)]
final class ImportDefaultSubcategoriesFromSupabaseCommand extends AbstractSupabaseMigrationCommand
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

        $io->title('Supabase default_subcategories → default_subcategories');
        if ($dryRun) {
            $io->note('DRY RUN');
        }

        $src  = $this->supabase->create();
        $tgt  = $this->em->getConnection();
        $rows = $src->fetchAllAssociative(
            'SELECT id, category_id, name, icon, "order", created_at, updated_at, deleted_at
             FROM default_subcategories ORDER BY created_at ASC'
        );

        if (empty($rows)) {
            $io->warning('No rows found.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Found <info>%d</info> rows.', count($rows)));

        $created = $skipped = $errors = 0;

        foreach ($rows as $row) {
            $id         = $this->toUuid($row['id']);
            $categoryId = $row['category_id'] !== null ? $this->toUuid($row['category_id']) : null;

            $exists = (bool) $tgt->fetchOne('SELECT 1 FROM default_subcategories WHERE id = ?', [$id]);
            if ($exists) {
                $io->writeln(sprintf('  <comment>SKIP</comment>  %s', $row['id']));
                ++$skipped;
                continue;
            }

            $io->writeln(sprintf(
                '  <info>IMPORT</info> %s → %s  [%s / %s]',
                $row['id'],
                $id,
                $row['category_id'] ?? 'none',
                $row['name'],
            ));

            if (!$dryRun) {
                try {
                    $tgt->executeStatement(
                        'INSERT INTO default_subcategories
                             (id, category_id, name, icon, "order", created_at, updated_at, deleted_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (id) DO NOTHING',
                        [
                            $id,
                            $categoryId,
                            $row['name'],
                            $row['icon'],
                            (int) ($row['order'] ?? 0),
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
