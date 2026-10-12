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
 *     php bin/console goveo:events:import-awin /tmp/eci.csv --dry-run
 *     php bin/console goveo:events:import-awin /tmp/eci.csv
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

    /** «Todo lo que viene»: tres años da de sobra para cualquier gira anunciada. */
    private const HORIZON_DAYS = 3 * 366;

    public function __construct(
        private readonly EventImporter $importer,
        private readonly AffiliateRelinker $relinker,
        private readonly WebPage $web,
        private readonly Connection $db,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('csv', InputArgument::REQUIRED, 'El CSV del feed de Awin.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de eventos nuevos.', '3000')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin subir ni guardar nada.')
            ->addOption('details', null, InputOption::VALUE_NONE, 'Lista también los descartados y por qué.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $dryRun  = (bool) $input->getOption('dry-run');
        $details = (bool) $input->getOption('details');
        $source  = new AwinElCorteInglesSource((string) $input->getArgument('csv'), $this->web);
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
