<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Command;

use App\Business\Application\BusinessPurger;
use App\Business\Domain\BusinessRepository;
use App\EventScraping\Application\AgendaPublisher;
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
 * `meta.origin` (`scraping_2026-09-23`).
 *
 *     php bin/console goveo:events:purge --origin=scraping_2026-09-23                      # enseña qué borraría
 *     php bin/console goveo:events:purge --origin=scraping_2026-09-23 --apply              # borra
 *     php bin/console goveo:events:purge --origin=… --what=videos --status=all --apply     # vídeos, validados o no
 *     php bin/console goveo:events:purge --origin=last --apply                             # la última pasada
 *     php bin/console goveo:events:purge --older-than=7 --apply                            # las de hace más de 7 días
 *
 * `last` y `--older-than` existen para las tareas de Dokploy: una fecha escrita
 * en la tarea obligaría a editarla antes de cada uso. `--older-than` con el
 * `--status=pending` por defecto es la limpieza de lo que nadie ha validado.
 *
 * - `--what`: `videos` (los eventos), `businesses` (las salas que creó) o `all`.
 * - `--status`: `pending` (sólo lo sin validar, por defecto) o `all`. Lo validado
 *   ya se ve en la app, y borrarlo por una fecha de importación es fácil de hacer
 *   sin querer: hay que pedirlo.
 *
 * **Los eventos**: fila, likes e imagen del almacenamiento.
 *
 * **Las salas**: con su carpeta de imágenes (`BusinessPurger`). Lo que haya
 * colgado de ellas depende de qué se borra:
 *
 * - con `all`, primero se van los eventos de esa pasada, y la sala sólo si ya
 *   no le queda ninguno —los de pasadas posteriores también cuelgan de ella—;
 * - con `businesses`, sus eventos **pasan a la Agenda de su ciudad** en vez de
 *   irse con ella: se ha pedido borrar salas, no eventos.
 *
 * Es para deshacer una pasada que salió mal —una fuente que leyó basura, una
 * prueba—, no para descartar cosas sueltas: eso es «Descartar» en el panel.
 * **Al borrar la fila se va también `external_ref`**, así que la siguiente
 * pasada lo vuelve a importar.
 */
#[AsCommand(
    name: 'goveo:events:purge',
    description: 'Borra del todo lo importado por una pasada del scraping (meta.origin): vídeos, salas o los dos.',
)]
final class PurgeScrapedEventsCommand extends Command
{
    private const WHAT   = ['videos', 'businesses', 'all'];
    private const STATUS = ['pending', 'all'];

    public function __construct(
        private readonly Connection $db,
        private readonly BunnyStorageService $storage,
        private readonly BusinessRepository $businesses,
        private readonly BusinessPurger $purger,
        private readonly AgendaPublisher $agenda,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('origin', null, InputOption::VALUE_REQUIRED, 'La pasada, como sale en el panel (scraping_AAAA-MM-DD), o «last» para la última.')
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'En vez de --origin: todas las pasadas de hace más de N días.')
            ->addOption('what', null, InputOption::VALUE_REQUIRED, 'Qué borrar: videos, businesses o all.', 'all')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'pending (sólo lo sin validar) o all (también lo validado).', 'pending')
            ->addOption('source', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sólo estas fuentes (berlin, clamores…).')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Borra de verdad (sin esto sólo lo enseña).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $origin    = trim((string) $input->getOption('origin'));
        $olderThan = $input->getOption('older-than');
        $what      = (string) $input->getOption('what');
        $status    = (string) $input->getOption('status');
        $apply     = (bool) $input->getOption('apply');
        $sources   = (array) $input->getOption('source');

        // Una de las dos, y siempre una: sin nada no se borra «todo lo
        // importado», porque un despiste en la orden no puede llevarse meses de
        // cartelera.
        if (($origin === '') === ($olderThan === null)) {
            $io->error('Indica --origin=scraping_AAAA-MM-DD, --origin=last o --older-than=DÍAS (una de las tres).');

            return Command::INVALID;
        }
        if ($olderThan !== null && (!ctype_digit((string) $olderThan) || (int) $olderThan < 1)) {
            $io->error('--older-than es un número de días, 1 o más.');

            return Command::INVALID;
        }
        if ($origin !== '' && $origin !== 'last' && !preg_match('/^scraping_\d{4}-\d{2}-\d{2}$/', $origin)) {
            $io->error('--origin es scraping_AAAA-MM-DD (sale en cada tarjeta del panel) o last.');

            return Command::INVALID;
        }
        if (!in_array($what, self::WHAT, true) || !in_array($status, self::STATUS, true)) {
            $io->error('--what es videos, businesses o all; --status es pending o all.');

            return Command::INVALID;
        }

        $origins = $this->origins($origin, $olderThan === null ? null : (int) $olderThan);
        if ($origins === []) {
            $io->success('No hay ninguna pasada que coincida.');

            return Command::SUCCESS;
        }
        $io->writeln('Pasadas: ' . implode(', ', $origins));
        $origin = implode(', ', $origins);

        $withVerified = $status === 'all';
        $videos       = $what !== 'businesses' ? $this->select('geostories', 'id, title, url, external_ref, verified_at', 'external_ref', $origins, $sources, $withVerified) : [];
        $venues       = $what !== 'videos' ? $this->select('business', 'id, name, city, external_ref, verified_at', 'name', $origins, $sources, $withVerified) : [];

        if ($videos === [] && $venues === []) {
            $io->success(sprintf('Nada que borrar de %s con esos filtros.', $origin));

            return Command::SUCCESS;
        }

        foreach ($venues as $venue) {
            $io->writeln(sprintf('  ★ %s sala: %s  <comment>%s</comment>', $this->mark($venue), $venue['name'], $venue['external_ref']));
        }
        foreach ($videos as $video) {
            $io->writeln(sprintf('  %s %s  <comment>%s</comment>', $this->mark($video), $video['title'] ?? '(sin título)', $video['external_ref']));
        }

        // Lo validado que se deja, para que no parezca que no existe.
        if (!$withVerified) {
            $left = [];
            if ($what !== 'businesses') {
                $left[] = count($this->select('geostories', 'id', 'id', $origins, $sources, true)) - count($videos) . ' vídeos';
            }
            if ($what !== 'videos') {
                $left[] = count($this->select('business', 'id', 'id', $origins, $sources, true)) - count($venues) . ' salas';
            }
            $io->note(sprintf('Validados de esa pasada que se dejan: %s. Para borrarlos también, --status=all.', implode(' y ', $left)));
        }

        if (!$apply) {
            $io->warning(sprintf('%d vídeos y %d salas por borrar. No se ha tocado nada: repite con --apply.', count($videos), count($venues)));

            return Command::SUCCESS;
        }

        foreach ($videos as $video) {
            // Primero la imagen: si fallara el borrado de la fila, quedaría una
            // ficha sin foto, que se ve y se arregla. Al revés quedaría una foto
            // en el almacenamiento sin nada que la nombre.
            $this->storage->deleteByUrl($video['url']);

            $this->db->transactional(function (Connection $db) use ($video): void {
                // Los likes no tienen clave ajena declarada, así que no se van solos.
                $db->executeStatement('DELETE FROM geostory_likes WHERE geostory_id = ?', [$video['id']]);
                $db->executeStatement('DELETE FROM geostories WHERE id = ?', [$video['id']]);
            });
        }

        $purgedVenues = 0;
        $movedVideos  = 0;
        foreach ($venues as $venue) {
            $left = (int) $this->db->fetchOne('SELECT COUNT(*) FROM geostories WHERE business_id = ?', [$venue['id']]);

            if ($left > 0 && $what === 'all') {
                $io->note(sprintf('Se deja la sala «%s»: le quedan %d eventos de otras pasadas.', $venue['name'], $left));
                continue;
            }

            // Sólo salas: sus eventos no se van con ella, pasan a la Agenda.
            if ($left > 0) {
                $movedVideos += $this->db->executeStatement(
                    'UPDATE geostories SET business_id = NULL, influencer_id = ?, updated_at = NOW() WHERE business_id = ?',
                    [$this->agenda->forCity($venue['city'] ?? 'Madrid'), $venue['id']],
                );
            }

            $business = $this->businesses->findById($venue['id']);
            if ($business !== null) {
                $this->purger->purge($business);
                ++$purgedVenues;
            }
        }

        $io->success(sprintf(
            '%d vídeos y %d salas borrados de %s%s. La próxima pasada los volverá a importar.',
            count($videos),
            $purgedVenues,
            $origin,
            $movedVideos > 0 ? sprintf(' (%d eventos pasados a la Agenda)', $movedVideos) : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * Lo de esa pasada en una tabla. `geostories` y `business` guardan el
     * origen igual —`external_ref` y `meta.origin`—, así que es la misma consulta.
     *
     * @param list<string> $origins
     * @param list<string> $sources
     *
     * @return list<array<string, mixed>>
     */
    private function select(string $table, string $columns, string $order, array $origins, array $sources, bool $withVerified): array
    {
        $where  = "external_ref IS NOT NULL AND meta->>'origin' IN (" . implode(', ', array_fill(0, count($origins), '?')) . ')';
        $params = $origins;

        if ($sources !== []) {
            $where .= ' AND (' . implode(' OR ', array_fill(0, count($sources), 'external_ref LIKE ?')) . ')';
            foreach ($sources as $source) {
                $params[] = addcslashes($source, '%_') . ':%';
            }
        }
        if (!$withVerified) {
            $where .= ' AND verified_at IS NULL';
        }

        return $this->db->fetchAllAssociative("SELECT {$columns} FROM {$table} WHERE {$where} ORDER BY {$order}", $params);
    }

    /**
     * Las pasadas a borrar. `scraping_AAAA-MM-DD` se ordena igual como texto
     * que como fecha, así que la última es la mayor y «hace más de N días» es
     * una comparación de cadenas.
     *
     * @return list<string>
     */
    private function origins(string $origin, ?int $olderThan): array
    {
        if ($olderThan === null && $origin !== 'last') {
            return [$origin];
        }

        $all = $this->db->fetchFirstColumn(
            "SELECT DISTINCT meta->>'origin' AS o FROM geostories WHERE external_ref IS NOT NULL AND meta->>'origin' LIKE 'scraping\\_%'
             UNION
             SELECT DISTINCT meta->>'origin' FROM business WHERE external_ref IS NOT NULL AND meta->>'origin' LIKE 'scraping\\_%'
             ORDER BY 1",
        );

        if ($olderThan === null) {
            return $all === [] ? [] : [end($all)];
        }

        $limit = 'scraping_' . (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid')))
            ->modify(sprintf('-%d days', $olderThan))->format('Y-m-d');

        return array_values(array_filter($all, fn (string $o) => $o < $limit));
    }

    /** @param array<string, mixed> $row */
    private function mark(array $row): string
    {
        return ($row['verified_at'] ?? null) !== null ? '<error>validado</error>' : '·';
    }
}
