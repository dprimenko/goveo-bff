<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Command;

use App\EventScraping\Application\AffiliateRelinker;
use App\EventScraping\Application\EventImporter;
use App\EventScraping\Application\EventWindow;
use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Source\AwinElCorteInglesSource;
use App\EventScraping\Infrastructure\WebPage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Importa la cartelera de Ticketing El Corte Inglés desde el CSV del feed de
 * productos de Awin, con el enlace de afiliado:
 *
 *     php bin/console goveo:events:import-awin --dry-run          # descarga el feed con AWIN_API_KEY
 *     php bin/console goveo:events:import-awin 'https://productdata.awin.com/…'
 *     php bin/console goveo:events:import-awin /tmp/eci.csv
 *     php bin/console goveo:events:import-awin --province=all   # toda España
 *
 * **Por ahora sólo la provincia de Madrid** (`--province`, Madrid por defecto).
 *
 * Lo normal es **sin argumento**: monta el enlace de descarga del feed con la
 * clave de la cuenta (`AWIN_API_KEY`, en el entorno, no en el repositorio) y lo
 * descarga. Así se lanza desde la terminal o los Schedules de Dokploy sin tener
 * que subir un fichero al contenedor. Admite el CSV en claro o en gzip.
 *
 * Entra **todo lo que viene**, sin la ventana de jueves a sábado del scraping
 * (decisión del usuario, 12-10-2026), sin validar, como el resto de lo
 * importado. Lo que no trae cartel lleva el de «no disponible». Lo que ya entró
 * por la web de su sala no se duplica: se le pone el enlace de afiliado (ver
 * `AffiliateRelinker`).
 *
 * Repetirlo con un CSV nuevo no duplica: lo ya importado se alarga si la fecha
 * de fin crece, y lo descartado en el panel sigue descartado.
 */
#[AsCommand(
    name: 'goveo:events:import-awin',
    description: 'Importa la cartelera de El Corte Inglés (CSV de Awin) como eventos sin validar.',
)]
final class ImportAwinEventsCommand extends Command
{
    /** Cartel para lo que llega sin imagen. */
    private const PLACEHOLDER = '/resources/events/cartel-no-disponible.jpg';

    /** El feed de Ticketing El Corte Inglés en Awin. */
    private const FEED_ID = 63703;

    /**
     * Sólo las columnas que lee `AwinElCorteInglesSource`: el enlace de Awin con
     * todas las del feed pasaba de dos mil caracteres y bajaba lo que no se usa.
     */
    private const COLUMNS = ['aw_deep_link', 'product_name', 'aw_product_id', 'merchant_product_id', 'merchant_image_url',
        'description', 'merchant_category', 'merchant_name', 'merchant_deep_link', 'merchant_product_second_category',
        'Tickets:event_date', 'Tickets:venue_name', 'Tickets:venue_address', 'Tickets:longitude', 'Tickets:latitude',
        'Tickets:event_location_city', 'Tickets:event_location_region', 'Tickets:event_location_country'];

    /** El feed entero son unos 3,5 MB; con margen por si crece. */
    private const MAX_FEED_BYTES = 200 * 1024 * 1024;

    /** «Todo lo que viene»: tres años da de sobra para cualquier gira anunciada. */
    private const HORIZON_DAYS = 3 * 366;

    public function __construct(
        private readonly EventImporter $importer,
        private readonly AffiliateRelinker $relinker,
        private readonly WebPage $web,
        private readonly Connection $db,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(AWIN_API_KEY)%')]
        private readonly string $apiKey,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('csv', InputArgument::OPTIONAL, 'El CSV del feed de Awin, o su URL de descarga. Sin él, se descarga con AWIN_API_KEY.')
            ->addOption('province', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sólo estas provincias; «all», toda España. Por ahora, Madrid.', ['Madrid'])
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de eventos nuevos.', '3000')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin subir ni guardar nada.')
            ->addOption('details', null, InputOption::VALUE_NONE, 'Lista también los descartados y por qué.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $dryRun  = (bool) $input->getOption('dry-run');
        $details = (bool) $input->getOption('details');
        $csv     = (string) ($input->getArgument('csv') ?? '') ?: $this->feedUrl();
        if ($csv === '') {
            $io->error('Falta el CSV: pásalo como argumento o pon AWIN_API_KEY.');

            return Command::INVALID;
        }
        if (preg_match('#^https?://#i', $csv) === 1) {
            $csv = $this->download($csv);
            if ($csv === null) {
                $io->error('No se pudo descargar el feed de Awin' . (($why = $this->web->lastError()) !== null ? ": {$why}" : ''));

                return Command::FAILURE;
            }
        }
        // Por ahora sólo Madrid (decisión del usuario, 12-10-2026): el feed trae
        // toda España.
        $provinces = array_values(array_filter((array) $input->getOption('province'), fn ($p) => $p !== '' && strtolower($p) !== 'all'));
        $source    = new AwinElCorteInglesSource($csv, $this->web, $provinces);
        $window  = EventWindow::nextDays(self::HORIZON_DAYS, [1, 2, 3, 4, 5, 6, 7]);

        $placeholder = @file_get_contents($this->projectDir . self::PLACEHOLDER);
        if ($placeholder === false) {
            $io->error('No se encuentra el cartel de «no disponible»: ' . self::PLACEHOLDER);

            return Command::FAILURE;
        }

        $events = $source->fetch();
        $io->writeln(sprintf('%d eventos en el CSV que se pueden usar.', count($events)));
        foreach ($source->discarded() as $reason => $n) {
            $io->writeln(sprintf('  <comment>fuera</comment> %s: %d', $reason, $n));
        }

        // 1. Lo que ya entró por la web de su sala: se le cambia el enlace.
        $io->section('Ya estaban: enlace de afiliado');
        $imported = $this->importedIds();
        $relinked = ['enlazado' => 0, 'ya enlazado' => 0];
        $fresh    = [];
        foreach ($events as $event) {
            if (isset($imported[$event->externalId]) || !$window->accepts($event)) {
                $fresh[] = $event;
                continue;
            }
            $same = $this->relinker->findSame($event);
            if ($same === null) {
                $fresh[] = $event;
                continue;
            }
            $changed = $same['link'] !== $event->link && ($dryRun || $this->relinker->relink($same['id'], (string) $event->link, $event->linkAction));
            ++$relinked[$changed ? 'enlazado' : 'ya enlazado'];
            if ($changed || $details) {
                $io->writeln(sprintf('  <info>↺</info> %s  %s → %s «%s»', $event->start->format('d/m'), $event->title, $same['source'] ?? '?', $same['title']));
            }
        }
        $io->writeln('  ' . implode(' · ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($relinked), $relinked)));

        // 2. El resto, como cualquier fuente.
        $io->section('Nuevos');
        $counts = [];
        $this->importer->run(
            self::only($source, $fresh),
            $window,
            $dryRun,
            max(1, (int) $input->getOption('limit')),
            function (string $outcome, ScrapedEvent $e, ?string $detail) use ($io, $details, &$counts): void {
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                $line = sprintf('%s  %s · %s  %s', $e->start->format('d/m/Y'), $e->title, $e->city, $detail !== null ? "→ {$detail}" : '');
                match (true) {
                    $outcome === 'created', $outcome === 'would-create' => $io->writeln("  <info>+</info> {$line}"),
                    $details                                           => $io->writeln("  <comment>·</comment> [{$outcome}] {$line}"),
                    default                                            => null,
                };
            },
            $placeholder,
        );

        ksort($counts);
        $io->writeln('  ' . ($counts === [] ? 'nada' : implode(' · ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($counts), $counts))));

        return Command::SUCCESS;
    }

    /** El enlace de descarga del feed, como lo genera Create-a-Feed de Awin. Vacío sin clave. */
    private function feedUrl(): string
    {
        if (trim($this->apiKey) === '') {
            return '';
        }

        return sprintf(
            'https://productdata.awin.com/datafeed/download/apikey/%s/language/es/fid/%d/rid/0/hasEnhancedFeeds/0/columns/%s/format/csv/delimiter/%%2C/compression/gzip/adultcontent/1/',
            rawurlencode(trim($this->apiKey)),
            self::FEED_ID,
            implode(',', array_map('rawurlencode', self::COLUMNS)),
        );
    }

    /** El feed a un fichero temporal, descomprimido si viene en gzip. */
    private function download(string $url): ?string
    {
        $body = $this->web->get($url, self::MAX_FEED_BYTES, 120);
        if ($body === null) {
            return null;
        }
        if (str_starts_with($body, "\x1f\x8b")) {
            $body = gzdecode($body);
            if ($body === false) {
                return null;
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'awin');
        file_put_contents($path, $body);
        register_shutdown_function(static fn () => @unlink($path));

        return $path;
    }

    /** @return array<string, true> ids de El Corte Inglés ya importados (también los descartados) */
    private function importedIds(): array
    {
        $prefix = AwinElCorteInglesSource::NAME . ':';
        $rows   = $this->db->fetchFirstColumn('SELECT external_ref FROM geostories WHERE external_ref LIKE ?', [$prefix . '%']);

        return array_fill_keys(array_map(fn ($ref) => substr((string) $ref, strlen($prefix)), $rows), true);
    }

    /**
     * La misma fuente, pero sólo con estos eventos: los que no estaban ya por
     * otra.
     *
     * @param list<ScrapedEvent> $events
     */
    private static function only(EventSource $source, array $events): EventSource
    {
        return new class($source, $events) implements EventSource {
            /** @param list<ScrapedEvent> $events */
            public function __construct(private readonly EventSource $inner, private readonly array $events) {}

            public function name(): string { return $this->inner->name(); }
            public function city(): string { return $this->inner->city(); }
            public function fetch(): iterable { return $this->events; }
            public function enrich(ScrapedEvent $event): ScrapedEvent { return $this->inner->enrich($event); }
            public function venueFor(ScrapedEvent $event): ?ScrapedVenue { return $this->inner->venueFor($event); }
        };
    }
}
