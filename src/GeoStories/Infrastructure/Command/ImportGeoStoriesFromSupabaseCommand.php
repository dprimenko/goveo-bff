<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Command;

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
 * Migrates Supabase `geostories` → local `geostories`.
 *
 * id           : already UUID — used as-is
 * category_id  : slug text nullable → UUID v5
 * business_id  : Supabase business.id (any text) nullable → UUID v5
 * influencer_id: already UUID nullable — used as-is
 * location     : fetched as WKT via ST_AsText() → inserted via ST_GeomFromText(?, 4326)
 *
 * Run AFTER goveo:migrate:supabase:categories, goveo:migrate:supabase:business, goveo:migrate:supabase:influencers.
 *
 * Usage:
 *   docker compose exec php php bin/console goveo:migrate:supabase:geostories
 */
#[AsCommand(
    name: 'goveo:migrate:supabase:geostories',
    description: 'Migrates Supabase geostories table to local DB (including PostGIS geometry).',
)]
final class ImportGeoStoriesFromSupabaseCommand extends AbstractSupabaseMigrationCommand
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

        $io->title('Supabase geostories → geostories');
        if ($dryRun) {
            $io->note('DRY RUN');
        }

        $src  = $this->supabase->create();
        $tgt  = $this->em->getConnection();

        // Ensure PostGIS functions are in the search_path on the Supabase pooler connection
        $src->executeStatement('SET search_path TO public, gis, extensions');

        $rows = $src->fetchAllAssociative(
            "SELECT id, title, description, thumbnail, url,
                    likes::integer AS likes, views::integer AS views,
                    gis.ST_AsText(location) AS location_wkt,
                    category_id, influencer_id, business_id, meta,
                    created_at, updated_at, deleted_at, verified_at, published_at, started_at, ended_at
             FROM geostories ORDER BY created_at ASC"
        );

        if (empty($rows)) {
            $io->warning('No rows found.');
            return Command::SUCCESS;
        }

        $total = count($rows);
        $io->writeln(sprintf('Found <info>%d</info> rows.', $total));

        $created = $skipped = $errors = 0;

        foreach ($rows as $i => $row) {
            // Some geostory IDs are UUIDs; others (older records) are Firestore-style text IDs — normalise all to UUID v5
            $rawId        = $row['id'];
            $id           = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $rawId)
                            ? $rawId
                            : $this->toUuid($rawId);
            $categoryId   = $row['category_id'] !== null ? $this->toUuid($row['category_id']) : null;
            $businessId   = $row['business_id'] !== null ? $this->toUuid($row['business_id']) : null;
            $influencerId = $row['influencer_id'] ?? null; // already UUID or null

            $exists = (bool) $tgt->fetchOne('SELECT 1 FROM geostories WHERE id = ?', [$id]);
            if ($exists) {
                ++$skipped;
                continue;
            }

            if (($i + 1) % self::PROGRESS_EVERY === 0 || $i === 0) {
                $io->writeln(sprintf(
                    '  [%d/%d] <info>IMPORT</info> %s  [%s]',
                    $i + 1,
                    $total,
                    $id,
                    $row['title'] ?? 'no title',
                ));
            }

            if (!$dryRun) {
                try {
                    $locationWkt = $row['location_wkt'] ?? null;
                    $this->insertGeoStory($tgt, $id, $row, $categoryId, $businessId, $influencerId, $locationWkt);
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

    private function insertGeoStory(
        \Doctrine\DBAL\Connection $tgt,
        string $id,
        array $row,
        ?string $categoryId,
        ?string $businessId,
        ?string $influencerId,
        ?string $locationWkt,
    ): void {
        // Location column uses ST_GeomFromText() when provided, plain NULL otherwise.
        $locationSql = $locationWkt !== null ? 'ST_GeomFromText(?, 4326)' : '?';

        $sql = "INSERT INTO geostories
                    (id, title, description, thumbnail, url, likes, views, location,
                     category_id, influencer_id, business_id, meta,
                     created_at, updated_at, deleted_at, verified_at, published_at, started_at, ended_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, {$locationSql}, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (id) DO NOTHING";

        $params = [
            $id,
            $row['title'],
            $row['description'],
            $row['thumbnail'],
            $row['url'],
            (int) ($row['likes'] ?? 0),
            (int) ($row['views'] ?? 0),
            $locationWkt ?? null,        // bound to ST_GeomFromText(?, 4326) or ?
            $categoryId,
            $influencerId,
            $businessId,
            $this->json($row['meta']),
            $this->ts($row['created_at']),
            $this->ts($row['updated_at']),
            $this->ts($row['deleted_at'] ?? null),
            $this->ts($row['verified_at'] ?? null),
            $this->ts($row['published_at'] ?? null),
            $this->ts($row['started_at'] ?? null),
            $this->ts($row['ended_at'] ?? null),
        ];

        $tgt->executeStatement($sql, $params);
    }
}
