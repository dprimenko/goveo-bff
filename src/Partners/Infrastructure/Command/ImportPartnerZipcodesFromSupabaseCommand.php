<?php

declare(strict_types=1);

namespace App\Partners\Infrastructure\Command;

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
 * Migrates Supabase `partner_zipcodes` → local `partner_zipcodes`.
 *
 * IDs are already UUIDs — used as-is.
 * partner_id is a slug string converted to deterministic UUID v5.
 * Run AFTER goveo:migrate:supabase:partners.
 *
 * Usage:
 *   docker compose exec php php bin/console goveo:migrate:supabase:partner-zipcodes
 */
#[AsCommand(
    name: 'goveo:migrate:supabase:partner-zipcodes',
    description: 'Migrates Supabase partner_zipcodes table to local DB.',
)]
final class ImportPartnerZipcodesFromSupabaseCommand extends AbstractSupabaseMigrationCommand
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

        $io->title('Supabase partner_zipcodes → partner_zipcodes');
        if ($dryRun) {
            $io->note('DRY RUN');
        }

        $src  = $this->supabase->create();
        $tgt  = $this->em->getConnection();
        $rows = $src->fetchAllAssociative(
            'SELECT id, partner_id, zipcode, deleted_at FROM partner_zipcodes ORDER BY partner_id, zipcode'
        );

        if (empty($rows)) {
            $io->warning('No rows found.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Found <info>%d</info> rows.', count($rows)));

        $created = $skipped = $errors = 0;

        foreach ($rows as $row) {
            $id        = $row['id']; // already UUID
            $partnerId = $this->toUuid($row['partner_id']);

            $exists = (bool) $tgt->fetchOne('SELECT 1 FROM partner_zipcodes WHERE id = ?', [$id]);
            if ($exists) {
                $io->writeln(sprintf('  <comment>SKIP</comment>  %s [%s]', $id, $row['zipcode']));
                ++$skipped;
                continue;
            }

            $io->writeln(sprintf(
                '  <info>IMPORT</info> %s  partner:%s → %s  zip:%s',
                $id,
                $row['partner_id'],
                $partnerId,
                $row['zipcode'],
            ));

            if (!$dryRun) {
                try {
                    $tgt->executeStatement(
                        'INSERT INTO partner_zipcodes (id, partner_id, zipcode, deleted_at)
                         VALUES (?, ?, ?, ?) ON CONFLICT (id) DO NOTHING',
                        [
                            $id,
                            $partnerId,
                            $row['zipcode'],
                            $this->ts($row['deleted_at'] ?? null),
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
