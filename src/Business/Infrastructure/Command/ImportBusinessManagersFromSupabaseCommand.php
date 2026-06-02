<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Command;

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
 * Migrates Supabase `business_managers` → local `business_managers`.
 *
 * Composite PK: (user_id, business_id).
 * user_id    : Firebase UID → UUID v5
 * business_id: Supabase business.id (any text) → UUID v5
 *
 * Run AFTER goveo:migrate:supabase:users and goveo:migrate:supabase:business.
 *
 * Usage:
 *   docker compose exec php php bin/console goveo:migrate:supabase:business-managers
 */
#[AsCommand(
    name: 'goveo:migrate:supabase:business-managers',
    description: 'Migrates Supabase business_managers table to local DB.',
)]
final class ImportBusinessManagersFromSupabaseCommand extends AbstractSupabaseMigrationCommand
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

        $io->title('Supabase business_managers → business_managers');
        if ($dryRun) {
            $io->note('DRY RUN');
        }

        $src  = $this->supabase->create();
        $tgt  = $this->em->getConnection();
        $rows = $src->fetchAllAssociative(
            'SELECT user_id, business_id, created_at, updated_at, deleted_at
             FROM business_managers ORDER BY created_at ASC'
        );

        if (empty($rows)) {
            $io->warning('No rows found.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Found <info>%d</info> rows.', count($rows)));

        $created = $skipped = $errors = 0;

        foreach ($rows as $row) {
            $userId     = $this->toUuid($row['user_id']);
            $businessId = $this->toUuid($row['business_id']);

            $exists = (bool) $tgt->fetchOne(
                'SELECT 1 FROM business_managers WHERE user_id = ? AND business_id = ?',
                [$userId, $businessId]
            );
            if ($exists) {
                ++$skipped;
                continue;
            }

            $io->writeln(sprintf(
                '  <info>IMPORT</info> user:%s → %s  business:%s → %s',
                $row['user_id'],
                $userId,
                $row['business_id'],
                $businessId,
            ));

            if (!$dryRun) {
                try {
                    $tgt->executeStatement(
                        'INSERT INTO business_managers (user_id, business_id, created_at, updated_at, deleted_at)
                         VALUES (?, ?, ?, ?, ?) ON CONFLICT (user_id, business_id) DO NOTHING',
                        [
                            $userId,
                            $businessId,
                            $this->ts($row['created_at']),
                            $this->ts($row['updated_at']),
                            $this->ts($row['deleted_at'] ?? null),
                        ]
                    );
                } catch (\Throwable $e) {
                    $io->warning(sprintf(
                        '  ERROR  user:%s business:%s — %s',
                        $row['user_id'],
                        $row['business_id'],
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
