<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Command;

use App\Business\Domain\CityLookup;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rellena `business.city` preguntándole a Google a qué municipio pertenece cada
 * punto del mapa.
 *
 * **Va a mano y no en el despliegue**: son cuatrocientas y pico llamadas a una
 * API de pago, y meterlas en una migración significa pagarlas otra vez en cada
 * entorno y que un despliegue se caiga porque Google no contesta.
 *
 * **Idempotente**: sólo mira a quien tiene la ciudad vacía. Volver a lanzarlo
 * después de un corte sigue donde se quedó; para rehacerlo entero está
 * `--force`, que la recalcula también a los que ya la tienen.
 *
 * Lo que no tiene coordenadas se queda sin ciudad y se cuenta aparte: ése es un
 * problema anterior —sin coordenadas un negocio tampoco sale en el mapa ni en el
 * feed— y lo arregla `goveo:business:backfill-location`.
 */
#[AsCommand(
    name: 'goveo:business:backfill-cities',
    description: 'Rellena la ciudad de los negocios por geocodificación inversa.',
)]
final class BackfillBusinessCitiesCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly CityLookup $cities,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Consulta y enseña, sin escribir')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Recalcula también los que ya tienen ciudad')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Como mucho, tantos negocios');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force  = (bool) $input->getOption('force');
        $limit  = $input->getOption('limit') === null ? null : max(1, (int) $input->getOption('limit'));

        $io->title('Ciudad de los negocios');
        if ($dryRun) {
            $io->note('DRY RUN: no se escribe nada');
        }

        // Los borrados también: uno recuperado tiene que salir en el filtro sin
        // que haya que acordarse de volver a pasar esto.
        $where = 'location IS NOT NULL' . ($force ? '' : ' AND city IS NULL');

        $rows = $this->db->fetchAllAssociative(
            "SELECT id, name, city,
                    ST_Y(location::geometry) AS lat,
                    ST_X(location::geometry) AS lng
               FROM business
              WHERE {$where}
              ORDER BY created_at"
            . ($limit === null ? '' : " LIMIT {$limit}"),
        );

        $sinCoordenadas = (int) $this->db->fetchOne(
            'SELECT count(*) FROM business WHERE location IS NULL AND deleted_at IS NULL',
        );

        $io->writeln(sprintf('  Por resolver: <info>%d</info>', count($rows)));
        if ($sinCoordenadas > 0) {
            $io->writeln(sprintf(
                '  Sin coordenadas (no se puede, y tampoco salen en el mapa): <comment>%d</comment>',
                $sinCoordenadas,
            ));
        }

        if ($rows === []) {
            $io->success('Nada que hacer.');

            return Command::SUCCESS;
        }

        $resueltos = 0;
        $fallidos  = 0;
        $progress  = $io->createProgressBar(count($rows));

        foreach ($rows as $row) {
            $city = $this->cities->cityAt((float) $row['lat'], (float) $row['lng']);

            if ($city === null) {
                ++$fallidos;
                $progress->advance();
                continue;
            }

            ++$resueltos;

            if (!$dryRun) {
                // Por SQL y no por el repositorio: recorrer cuatrocientas
                // entidades por el ORM sólo para tocar una columna llena el
                // mapa de identidad para nada, y esto no dispara ninguna regla
                // de negocio —tocar `updated_at` diría que la ficha ha
                // cambiado, y no ha cambiado: la mira por primera vez.
                $this->db->executeStatement(
                    'UPDATE business SET city = ? WHERE id = ?',
                    [$city, $row['id']],
                );
            }

            $progress->advance();
        }

        $progress->finish();
        $io->newLine(2);

        if ($fallidos > 0) {
            $io->warning(sprintf(
                '%d sin resolver. Suele ser la clave (GOOGLE_MAPS_API_KEY) o la cuota: mira el log, '
                . 'y vuelve a lanzarlo — sólo reintenta los que quedan.',
                $fallidos,
            ));
        }

        $io->success(sprintf('%d negocios con ciudad%s.', $resueltos, $dryRun ? ' (simulado)' : ''));

        return Command::SUCCESS;
    }
}
