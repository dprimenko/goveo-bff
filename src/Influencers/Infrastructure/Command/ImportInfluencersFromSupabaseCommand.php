<?php

declare(strict_types=1);

namespace App\Influencers\Infrastructure\Command;

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
 * Migrates Supabase `influencers` → local `influencers`.
 *
 * IDs are already UUIDs — used as-is.
 * user_id is a Firebase Auth UID converted to deterministic UUID v5.
 * Run AFTER goveo:migrate:supabase:users.
 *
 * Usage:
 *   docker compose exec php php bin/console goveo:migrate:supabase:influencers
 */
#[AsCommand(
    name: 'goveo:migrate:supabase:influencers',
    description: 'Migrates Supabase influencers table to local DB.',
)]
final class ImportInfluencersFromSupabaseCommand extends AbstractSupabaseMigrationCommand
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

        $io->title('Supabase influencers → influencers');
        if ($dryRun) {
            $io->note('DRY RUN');
        }

        $src  = $this->supabase->create();
        $tgt  = $this->em->getConnection();
        $rows = $src->fetchAllAssociative(
            'SELECT id, user_id, username, name, avatar, bio, meta, created_at, updated_at, deleted_at, verified_at
             FROM influencers ORDER BY created_at ASC'
        );

        if (empty($rows)) {
            $io->warning('No rows found.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Found <info>%d</info> rows.', count($rows)));

        $created = $skipped = $errors = 0;

        foreach ($rows as $row) {
            $id     = $row['id']; // already UUID
            $userId = $this->toUuid($row['user_id']);

            $exists = (bool) $tgt->fetchOne('SELECT 1 FROM influencers WHERE id = ?', [$id]);
            if ($exists) {
                $io->writeln(sprintf('  <comment>SKIP</comment>  %s [%s]', $id, $row['username']));
                ++$skipped;
                continue;
            }

            $io->writeln(sprintf(
                '  <info>IMPORT</info> %s  user:%s → %s  [%s]',
                $id,
                $row['user_id'],
                $userId,
                $row['username'],
            ));

            if (!$dryRun) {
                try {
                    $tgt->executeStatement(
                        'INSERT INTO influencers
                             (id, user_id, username, name, avatar, bio, meta, created_at, updated_at, deleted_at, verified_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (id) DO NOTHING',
                        [
                            $id,
                            $userId,
                            $row['username'],
                            $row['name'],
                            $row['avatar'],
                            $row['bio'],
                            $this->json($row['meta']),
                            $this->ts($row['created_at']),
                            $this->ts($row['updated_at']),
                            $this->ts($row['deleted_at'] ?? null),
                            $this->ts($row['verified_at'] ?? null),
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
