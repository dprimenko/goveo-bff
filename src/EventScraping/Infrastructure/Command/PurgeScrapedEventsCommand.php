<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Command;

use App\Business\Application\BusinessPurger;
use App\Business\Domain\BusinessRepository;
use App\Shared\Infrastructure\Storage\BunnyStorageService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Borra **del todo** lo que importó una pasada del scraping, por su
 * `meta.origin` (`scraping_2026-09-23`): los eventos —fila, likes e imagen— y
 * las salas que creó —con su carpeta de imágenes—.
 *
 * Una sala sólo se borra si ya no le queda ningún evento: los de pasadas
 * posteriores también cuelgan de ella, y llevárselos por borrar otra pasada no
 * es lo que se ha pedido. Se dice cuáles se dejan.
 *
 *     php bin/console goveo:events:purge --origin=scraping_2026-09-23           # enseña qué borraría
 *     php bin/console goveo:events:purge --origin=scraping_2026-09-23 --apply   # borra
 *
 * Es para deshacer una pasada que salió mal —una fuente que leyó basura, una
 * prueba—, no para descartar eventos sueltos: eso es «Descartar» en el panel.
 *
 * **Al borrar la fila se va también `external_ref`**, así que la siguiente
 * pasada vuelve a importar esos eventos. Es lo que se quiere al rehacer una
 * pasada; si lo que se quiere es que no vuelvan, se descartan en el panel.
 *
 * **Lo validado no se toca** salvo con `--include-verified`: eso ya se ve en la
 * app, y borrarlo por una fecha de importación es fácil de hacer sin querer.
 */
#[AsCommand(
    name: 'goveo:events:purge',
    description: 'Borra del todo lo importado por una pasada del scraping (meta.origin).',
)]
final class PurgeScrapedEventsCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly BunnyStorageService $storage,
        private readonly BusinessRepository $businesses,
        private readonly BusinessPurger $purger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('origin', null, InputOption::VALUE_REQUIRED, 'La pasada, como sale en el panel: scraping_AAAA-MM-DD.')
            ->addOption('source', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sólo estas fuentes (berlin, clamores…).')
            ->addOption('include-verified', null, InputOption::VALUE_NONE, 'Borra también lo ya validado, que se está viendo en la app.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Borra de verdad (sin esto sólo lo enseña).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $origin = trim((string) $input->getOption('origin'));
        $apply  = (bool) $input->getOption('apply');

        // Sin `--origin` no se borra «todo lo importado»: un despiste en la
        // orden no puede llevarse meses de cartelera.
        if (!preg_match('/^scraping_\d{4}-\d{2}-\d{2}$/', $origin)) {
            $io->error('Indica la pasada con --origin=scraping_AAAA-MM-DD (sale en cada tarjeta del panel).');

            return Command::INVALID;
        }

        $where  = "external_ref IS NOT NULL AND meta->>'origin' = ?";
        $params = [$origin];

        $sources = (array) $input->getOption('source');
        if ($sources !== []) {
            $where .= ' AND (' . implode(' OR ', array_fill(0, count($sources), 'external_ref LIKE ?')) . ')';
            foreach ($sources as $source) {
                $params[] = addcslashes($source, '%_') . ':%';
            }
        }

        $verified = (int) $this->db->fetchOne("SELECT COUNT(*) FROM geostories WHERE {$where} AND verified_at IS NOT NULL", $params);
        if (!$input->getOption('include-verified')) {
            $where .= ' AND verified_at IS NULL';
        }

        $rows = $this->db->fetchAllAssociative(
            "SELECT id, title, url, external_ref, verified_at FROM geostories WHERE {$where} ORDER BY external_ref",
            $params,
        );

        // Las salas que creó esa pasada. Con la misma regla que los eventos:
        // lo validado, sólo si se pide.
        $venueWhere  = "external_ref IS NOT NULL AND meta->>'origin' = ?";
        $venueParams = [$origin];
        if ($sources !== []) {
            $venueWhere .= ' AND (' . implode(' OR ', array_fill(0, count($sources), 'external_ref LIKE ?')) . ')';
            foreach ($sources as $source) {
                $venueParams[] = addcslashes($source, '%_') . ':%';
            }
        }
        if (!$input->getOption('include-verified')) {
            $venueWhere .= ' AND verified_at IS NULL';
        }
        $venues = $this->db->fetchAllAssociative(
            "SELECT id, name, external_ref FROM business WHERE {$venueWhere} ORDER BY name",
            $venueParams,
        );

        if ($rows === [] && $venues === []) {
            $io->success(sprintf('Nada que borrar de %s.', $origin));

            return Command::SUCCESS;
        }

        foreach ($venues as $venue) {
            $io->writeln(sprintf('  ★ sala: %s  <comment>%s</comment>', $venue['name'], $venue['external_ref']));
        }

        foreach ($rows as $row) {
            $io->writeln(sprintf(
                '  %s %s  <comment>%s</comment>',
                $row['verified_at'] !== null ? '<error>validado</error>' : '·',
                $row['title'] ?? '(sin título)',
                $row['external_ref'],
            ));
        }

        if ($verified > 0 && !$input->getOption('include-verified')) {
            $io->note(sprintf('%d ya validados de esa pasada se dejan: se están viendo en la app. Para borrarlos también, --include-verified.', $verified));
        }

        if (!$apply) {
            $io->warning(sprintf('%d eventos y %d salas por borrar. No se ha tocado nada: repite con --apply.', count($rows), count($venues)));

            return Command::SUCCESS;
        }

        foreach ($rows as $row) {
            // Primero la imagen: si fallara el borrado de la fila, quedaría una
            // ficha sin foto, que se ve y se arregla. Al revés quedaría una foto
            // en el almacenamiento sin nada que la nombre.
            $this->storage->deleteByUrl($row['url']);

            $this->db->transactional(function (Connection $db) use ($row): void {
                // Los likes no tienen clave ajena declarada, así que no se van solos.
                $db->executeStatement('DELETE FROM geostory_likes WHERE geostory_id = ?', [$row['id']]);
                $db->executeStatement('DELETE FROM geostories WHERE id = ?', [$row['id']]);
            });
        }

        // Después de los eventos: así se sabe qué salas se han quedado vacías.
        $purgedVenues = 0;
        foreach ($venues as $venue) {
            $left = (int) $this->db->fetchOne('SELECT COUNT(*) FROM geostories WHERE business_id = ?', [$venue['id']]);
            if ($left > 0) {
                $io->note(sprintf('Se deja la sala «%s»: le quedan %d eventos de otras pasadas.', $venue['name'], $left));
                continue;
            }

            $business = $this->businesses->findById($venue['id']);
            if ($business !== null) {
                $this->purger->purge($business);
                ++$purgedVenues;
            }
        }

        $io->success(sprintf(
            '%d eventos y %d salas borrados de %s. La próxima pasada los volverá a importar.',
            count($rows),
            $purgedVenues,
            $origin,
        ));

        return Command::SUCCESS;
    }
}
