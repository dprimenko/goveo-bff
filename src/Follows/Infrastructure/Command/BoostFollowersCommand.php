<?php

declare(strict_types=1);

namespace App\Follows\Infrastructure\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Suma seguidores de relleno (`meta.followers`) a negocios e influencers: a
 * cada uno, un número al azar dentro del rango.
 *
 * Es el mismo relleno que ya traían las fichas importadas para no salir vacías
 * (ver `FollowerCounter`): lo que se publica es `meta.followers` **más** los
 * seguidores reales, así que esto sólo mueve el relleno; los de verdad siguen
 * contando aparte y no se tocan.
 *
 * **Suma, no sustituye**: lanzarlo dos veces suma dos veces. Con `--dry-run`
 * se ve antes cuánto le tocaría a cada uno.
 *
 *   goveo:followers:boost --min=5 --max=40 --dry-run
 *   goveo:followers:boost --min=5 --max=40 --type=business
 *   goveo:followers:boost --min=100 --max=300 --only=<id o slug/usuario>
 */
#[AsCommand(
    name: 'goveo:followers:boost',
    description: 'Suma a negocios e influencers un número aleatorio de seguidores de relleno (meta.followers).',
)]
final class BoostFollowersCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('min', null, InputOption::VALUE_REQUIRED, 'Mínimo que se suma a cada uno')
            ->addOption('max', null, InputOption::VALUE_REQUIRED, 'Máximo que se suma a cada uno')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'business, influencer o all', 'all')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Sólo uno: id, slug del negocio o usuario del influencer')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que sumaría, sin escribir');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $type   = (string) $input->getOption('type');
        $only   = $input->getOption('only');
        $min    = $input->getOption('min');
        $max    = $input->getOption('max');

        if (!is_numeric($min) || !is_numeric($max) || (int) $min < 0 || (int) $max < (int) $min) {
            $io->error('Hace falta --min y --max, enteros, con 0 <= min <= max.');

            return Command::INVALID;
        }
        if (!in_array($type, ['business', 'influencer', 'all'], true)) {
            $io->error('--type tiene que ser business, influencer o all.');

            return Command::INVALID;
        }
        [$min, $max] = [(int) $min, (int) $max];

        $io->title(sprintf('Seguidores de relleno: +%d a +%d%s', $min, $max, $dryRun ? ' (simulado)' : ''));

        $targets = [];
        if ($type !== 'influencer') {
            $targets[] = ['business', 'Negocios', 'name', "id::text = :only OR slug = :only"];
        }
        if ($type !== 'business') {
            $targets[] = ['influencers', 'Influencers', 'name', "id::text = :only OR username = :only"];
        }

        foreach ($targets as [$table, $label, $nameColumn, $onlyWhere]) {
            $where  = 'deleted_at IS NULL';
            $params = [];
            if (is_string($only) && $only !== '') {
                $where         .= " AND ({$onlyWhere})";
                $params['only'] = $only;
            }

            $rows = $this->db->fetchAllAssociative(
                "SELECT id, {$nameColumn} AS name, meta::jsonb->>'followers' AS followers
                   FROM {$table} WHERE {$where} ORDER BY {$nameColumn}",
                $params,
            );

            $added = 0;
            foreach ($rows as $row) {
                $current = is_numeric($row['followers']) ? max(0, (int) $row['followers']) : 0;
                $extra   = random_int($min, $max);
                $added  += $extra;

                if ($output->isVerbose() || $dryRun) {
                    $io->writeln(sprintf('  %s: %d → <info>%d</info>', $row['name'], $current, $current + $extra));
                }

                if (!$dryRun) {
                    // Por SQL y sólo esa clave, como los demás rellenos: no es un
                    // cambio de la ficha y no mueve `updated_at`.
                    $this->db->executeStatement(
                        "UPDATE {$table}
                            SET meta = jsonb_set(COALESCE(meta::jsonb, '{}'::jsonb), '{followers}', to_jsonb(?::int))::json
                          WHERE id = ?",
                        [$current + $extra, $row['id']],
                    );
                }
            }

            $io->writeln(sprintf(
                '%s: <info>%d</info>, +%d seguidores en total%s.',
                $label,
                count($rows),
                $added,
                $rows === [] ? '' : sprintf(' (media %.1f)', $added / count($rows)),
            ));
        }

        $io->success($dryRun ? 'Simulado: no se ha escrito nada.' : 'Hecho.');

        return Command::SUCCESS;
    }
}
