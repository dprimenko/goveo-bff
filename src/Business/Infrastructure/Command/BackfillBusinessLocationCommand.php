<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Calcula `business.location` a partir del `geohash` que trae `meta`.
 *
 * El origen no guarda coordenadas: sólo un geohash dentro de `meta`. Esto ya lo
 * hacía la migración `Version20260604140000`, pero **una migración se ejecuta una
 * vez y en un momento fijo**. En un entorno nuevo las migraciones corren al
 * arrancar, antes de importar nada, así que aquel `UPDATE` no encuentra filas y
 * los negocios quedan sin ubicación para siempre.
 *
 * Sin ubicación un negocio no existe de cara al público: el listado, el mapa y la
 * búsqueda por cercanía filtran por `location IS NOT NULL`.
 *
 * Es idempotente: por defecto sólo toca los que están a nulo.
 */
#[AsCommand(
    name: 'goveo:business:backfill-location',
    description: 'Rellena la ubicación de los negocios a partir del geohash de meta.',
)]
final class BackfillBusinessLocationCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Recalcula también los que ya tienen ubicación');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo informa, no escribe');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $all     = (bool) $input->getOption('all');
        $dryRun  = (bool) $input->getOption('dry-run');

        $io->title('Ubicación de negocios desde geohash');

        $scope = $all ? '' : ' AND location IS NULL';

        $total     = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM business');
        $sinUbicar = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM business WHERE location IS NULL');
        $candidatos = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM business WHERE meta->>'geohash' IS NOT NULL{$scope}"
        );

        $io->listing([
            sprintf('negocios: %d', $total),
            sprintf('sin ubicación: %d', $sinUbicar),
            sprintf('con geohash utilizable: %d', $candidatos),
        ]);

        if ($candidatos === 0) {
            $io->success('No hay nada que rellenar.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->note(sprintf('En seco: se habrían actualizado %d.', $candidatos));

            return Command::SUCCESS;
        }

        $updated = $this->connection->executeStatement(
            "UPDATE business
             SET location = ST_SetSRID(ST_PointFromGeoHash(meta->>'geohash'), 4326)
             WHERE meta->>'geohash' IS NOT NULL{$scope}"
        );

        $io->success(sprintf('%d negocios ubicados.', $updated));

        // Un negocio sin geohash no es un error del comando, pero sí algo que
        // alguien tiene que mirar: no aparecerá en el mapa ni en el listado.
        $huerfanos = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM business WHERE location IS NULL AND deleted_at IS NULL"
        );
        if ($huerfanos > 0) {
            $io->warning(sprintf(
                '%d negocios siguen sin ubicación (sin geohash en meta): no saldrán en el listado ni en el mapa.',
                $huerfanos,
            ));
        }

        return Command::SUCCESS;
    }
}
