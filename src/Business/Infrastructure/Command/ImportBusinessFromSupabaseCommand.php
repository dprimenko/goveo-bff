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
 * Migrates Supabase `business` → local `business`.
 *
 * id        : any Supabase text (Firestore ID or UUID-like) → UUID v5 deterministic
 * category_id: slug text → UUID v5
 * creator_id : Firebase UID → UUID v5
 * partner_id : slug text nullable → UUID v5 (if set)
 *
 * Run AFTER goveo:migrate:supabase:categories, goveo:migrate:supabase:users, goveo:migrate:supabase:partners.
 *
 * Usage:
 *   docker compose exec php php bin/console goveo:migrate:supabase:business
 */
#[AsCommand(
    name: 'goveo:migrate:supabase:business',
    description: 'Migrates Supabase business table to local DB.',
)]
final class ImportBusinessFromSupabaseCommand extends AbstractSupabaseMigrationCommand
{
    private const PROGRESS_EVERY = 50;

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

        $io->title('Supabase business → business');
        if ($dryRun) {
            $io->note('DRY RUN');
        }

        $src  = $this->supabase->create();
        $tgt  = $this->em->getConnection();
        $rows = $src->fetchAllAssociative(
            'SELECT id, slug, name, description, avatar, main_image,
                    category_id, creator_id, partner_id, meta,
                    created_at, updated_at, deleted_at, verified_at
             FROM business ORDER BY created_at ASC'
        );

        if (empty($rows)) {
            $io->warning('No rows found.');
            return Command::SUCCESS;
        }

        $total = count($rows);
        $io->writeln(sprintf('Found <info>%d</info> rows.', $total));

        $created = $skipped = $errors = 0;

        foreach ($rows as $i => $row) {
            $id         = $this->toUuid($row['id']);
            $categoryId = $this->toUuid($row['category_id']);
            $creatorId  = $this->toUuid($row['creator_id']);
            $partnerId  = $row['partner_id'] !== null ? $this->toUuid($row['partner_id']) : null;

            $exists = (bool) $tgt->fetchOne('SELECT 1 FROM business WHERE id = ?', [$id]);
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
                    $row['slug'],
                ));
            }

            if (!$dryRun) {
                try {
                    $tgt->executeStatement(
                        'INSERT INTO business
                             (id, slug, name, description, avatar, main_image,
                              category_id, creator_id, partner_id, meta,
                              created_at, updated_at, deleted_at, verified_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (id) DO NOTHING',
                        [
                            $id,
                            $row['slug'],
                            $row['name'],
                            $row['description'],
                            $row['avatar'],
                            $row['main_image'],
                            $categoryId,
                            $creatorId,
                            $partnerId,
                            $this->json($row['meta']),
                            $this->ts($row['created_at']),
                            $this->ts($row['updated_at']),
                            $this->ts($row['deleted_at'] ?? null),
                            $this->ts($row['verified_at'] ?? null),
                        ]
                    );
                } catch (\Throwable $e) {
                    $io->warning(sprintf(
                        '  ERROR  %s [%s] — %s',
                        $row['id'],
                        $row['slug'],
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
