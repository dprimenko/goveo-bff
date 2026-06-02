<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Command;

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
 * Migrates Supabase `users` → local `users`.
 *
 * IDs are Firebase Auth UIDs converted to deterministic UUID v5.
 *
 * Usage:
 *   docker compose exec php php bin/console goveo:migrate:supabase:users
 *   docker compose exec php php bin/console goveo:migrate:supabase:users --dry-run
 */
#[AsCommand(
    name: 'goveo:migrate:supabase:users',
    description: 'Migrates Supabase users table to local DB.',
)]
final class ImportUsersFromSupabaseCommand extends AbstractSupabaseMigrationCommand
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

        $io->title('Supabase users → users');
        if ($dryRun) {
            $io->note('DRY RUN');
        }

        $src  = $this->supabase->create();
        $tgt  = $this->em->getConnection();
        $rows = $src->fetchAllAssociative(
            'SELECT id, email, name, profile_image, created_at, updated_at, deleted_at, verified_at
             FROM users ORDER BY created_at ASC'
        );

        if (empty($rows)) {
            $io->warning('No rows found.');
            return Command::SUCCESS;
        }

        $total = count($rows);
        $io->writeln(sprintf('Found <info>%d</info> rows.', $total));

        $created = $skipped = $errors = 0;

        foreach ($rows as $i => $row) {
            $id = $this->toUuid($row['id']);

            $exists = (bool) $tgt->fetchOne('SELECT 1 FROM users WHERE id = ?', [$id]);
            if ($exists) {
                ++$skipped;
                continue;
            }

            if (($i + 1) % self::PROGRESS_EVERY === 0 || $i === 0) {
                $io->writeln(sprintf(
                    '  [%d/%d] <info>IMPORT</info> %s → %s  [%s]',
                    $i + 1,
                    $total,
                    $row['id'],
                    $id,
                    $row['email'] ?? 'no-email',
                ));
            }

            if (!$dryRun) {
                try {
                    $tgt->executeStatement(
                        'INSERT INTO users (id, email, name, profile_image, created_at, updated_at, deleted_at, verified_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (id) DO NOTHING',
                        [
                            $id,
                            $row['email'],
                            $row['name'],
                            $row['profile_image'],
                            $this->ts($row['created_at']),
                            $this->ts($row['updated_at']),
                            $this->ts($row['deleted_at'] ?? null),
                            $this->ts($row['verified_at'] ?? null),
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
