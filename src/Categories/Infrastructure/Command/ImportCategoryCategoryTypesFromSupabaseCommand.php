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
 * Migrates Supabase `categories_category_types` join table → local `categories_category_types`.
 *
 * Both category_id and type_id are slug strings converted to deterministic UUID v5.
 * Run AFTER goveo:migrate:supabase:categories and goveo:migrate:supabase:category-types.
 *
 * Usage:
 *   docker compose exec php php bin/console goveo:migrate:supabase:category-types-mapping
 */
#[AsCommand(
    name: 'goveo:migrate:supabase:category-types-mapping',
    description: 'Migrates Supabase categories_category_types join table to local DB.',
)]
final class ImportCategoryCategoryTypesFromSupabaseCommand extends AbstractSupabaseMigrationCommand
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

        $io->title('Supabase categories_category_types → categories_category_types');
        if ($dryRun) {
            $io->note('DRY RUN');
        }

        $src  = $this->supabase->create();
        $tgt  = $this->em->getConnection();
        $rows = $src->fetchAllAssociative(
            'SELECT category_id, type_id FROM categories_category_types ORDER BY category_id, type_id'
        );

        if (empty($rows)) {
            $io->warning('No rows found.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Found <info>%d</info> rows.', count($rows)));

        $created = $skipped = $errors = 0;

        foreach ($rows as $row) {
            $categoryUuid = $this->toUuid($row['category_id']);
            $typeUuid     = $this->toUuid($row['type_id']);

            $exists = (bool) $tgt->fetchOne(
                'SELECT 1 FROM categories_category_types WHERE category_id = ? AND type_id = ?',
                [$categoryUuid, $typeUuid]
            );
            if ($exists) {
                ++$skipped;
                continue;
            }

            $io->writeln(sprintf(
                '  <info>IMPORT</info> %s/%s → %s/%s',
                $row['category_id'],
                $row['type_id'],
                $categoryUuid,
                $typeUuid,
            ));

            if (!$dryRun) {
                try {
                    $tgt->executeStatement(
                        'INSERT INTO categories_category_types (category_id, type_id)
                         VALUES (?, ?) ON CONFLICT (category_id, type_id) DO NOTHING',
                        [$categoryUuid, $typeUuid]
                    );
                } catch (\Throwable $e) {
                    $io->warning(sprintf(
                        '  ERROR  %s/%s — %s',
                        $row['category_id'],
                        $row['type_id'],
                        $e->getMessage(),
                    ));
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
