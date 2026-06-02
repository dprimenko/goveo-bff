<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Command;

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
 * Migrates Supabase `user_notifications_devices` → local `user_notifications_devices`.
 *
 * id     : already UUID — used as-is
 * user_id: Firebase Auth UID text nullable → UUID v5 (if set)
 *
 * Run AFTER goveo:migrate:supabase:users.
 *
 * Usage:
 *   docker compose exec php php bin/console goveo:migrate:supabase:notification-devices
 */
#[AsCommand(
    name: 'goveo:migrate:supabase:notification-devices',
    description: 'Migrates Supabase user_notifications_devices table to local DB.',
)]
final class ImportUserNotificationDevicesFromSupabaseCommand extends AbstractSupabaseMigrationCommand
{
    private const PROGRESS_EVERY = 100;

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

        $io->title('Supabase user_notifications_devices → user_notifications_devices');
        if ($dryRun) {
            $io->note('DRY RUN');
        }

        $src  = $this->supabase->create();
        $tgt  = $this->em->getConnection();
        $rows = $src->fetchAllAssociative(
            'SELECT id, user_id, device_id, device_info, created_at, updated_at
             FROM user_notifications_devices ORDER BY created_at ASC'
        );

        if (empty($rows)) {
            $io->warning('No rows found.');
            return Command::SUCCESS;
        }

        $total = count($rows);
        $io->writeln(sprintf('Found <info>%d</info> rows.', $total));

        $created = $skipped = $errors = 0;

        foreach ($rows as $i => $row) {
            $id     = $row['id']; // already UUID
            $userId = $row['user_id'] !== null ? $this->toUuid($row['user_id']) : null;

            $exists = (bool) $tgt->fetchOne('SELECT 1 FROM user_notifications_devices WHERE id = ?', [$id]);
            if ($exists) {
                ++$skipped;
                continue;
            }

            if (($i + 1) % self::PROGRESS_EVERY === 0 || $i === 0) {
                $io->writeln(sprintf(
                    '  [%d/%d] <info>IMPORT</info> %s  device:%s',
                    $i + 1,
                    $total,
                    $id,
                    $row['device_id'],
                ));
            }

            if (!$dryRun) {
                try {
                    $tgt->executeStatement(
                        'INSERT INTO user_notifications_devices (id, user_id, device_id, device_info, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (id) DO NOTHING',
                        [
                            $id,
                            $userId,
                            $row['device_id'],
                            $row['device_info'],
                            $this->ts($row['created_at']),
                            $this->ts($row['updated_at']),
                        ]
                    );
                } catch (\Throwable $e) {
                    $io->warning(sprintf('  ERROR  %s — %s', $id, $e->getMessage()));
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
