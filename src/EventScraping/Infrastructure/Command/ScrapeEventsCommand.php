<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Command;

use App\EventScraping\Application\EventImporter;
use App\EventScraping\Application\EventWindow;
use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Importa eventos de las webs configuradas (`EventSource`) como publicaciones
 * sin validar. Pensado para el cron de Dokploy:
 *
 *     php bin/console goveo:events:scrape                 # todas las ciudades
 *     php bin/console goveo:events:scrape --city=Madrid   # sólo las fuentes de Madrid
 *
 * Se puede lanzar tantas veces como se quiera: lo ya importado —incluido lo que
 * se descartó en el panel— no se vuelve a crear.
 */
#[AsCommand(
    name: 'goveo:events:scrape',
    description: 'Importa eventos de salas y agendas como publicaciones sin validar.',
)]
final class ScrapeEventsCommand extends Command
{
    /** @param iterable<EventSource> $sources */
    public function __construct(
        private readonly EventImporter $importer,
        #[AutowireIterator('goveo.event_source')]
        private readonly iterable $sources,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('city', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sólo las fuentes de estas ciudades (Madrid, Málaga…). Sin tildes ni mayúsculas también vale.')
            ->addOption('source', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sólo estas fuentes (madrid-datos, berlin, clamores, calderon).')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Cuántos días hacia delante.', '30')
            ->addOption('weekdays', null, InputOption::VALUE_REQUIRED, 'Días aceptados, ISO (1 lunes … 7 domingo).', '4,5,6')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de eventos nuevos por fuente.', '150')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin subir ni guardar nada.')
            ->addOption('details', null, InputOption::VALUE_NONE, 'Lista también los descartados y por qué.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $only     = (array) $input->getOption('source');
        $cities   = array_map(self::normalize(...), (array) $input->getOption('city'));
        $matched  = 0;
        $dryRun   = (bool) $input->getOption('dry-run');
        $details  = (bool) $input->getOption('details');
        $weekdays = array_values(array_filter(array_map('intval', explode(',', (string) $input->getOption('weekdays')))));
        $window   = EventWindow::nextDays(max(1, (int) $input->getOption('days')), $weekdays);
        $limit    = max(1, (int) $input->getOption('limit'));
        $failed   = false;

        foreach ($this->sources as $source) {
            if ($only !== [] && !in_array($source->name(), $only, true)) {
                continue;
            }
            if ($cities !== [] && !in_array(self::normalize($source->city()), $cities, true)) {
                continue;
            }
            ++$matched;

            $io->section(sprintf('%s · %s', $source->name(), $source->city()));
            $counts = [];

            try {
                $this->importer->run($source, $window, $dryRun, $limit, function (string $outcome, ScrapedEvent $e, ?string $detail) use ($io, $details, &$counts): void {
                    $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;

                    $line = sprintf('%s  %s  %s', $e->start->format('D d/m H:i'), $e->title, $detail !== null ? "→ {$detail}" : '');
                    match (true) {
                        $outcome === 'created', $outcome === 'would-create' => $io->writeln("  <info>+</info> {$line}"),
                        $details                                           => $io->writeln("  <comment>·</comment> [{$outcome}] {$line}"),
                        default                                            => null,
                    };
                });
            } catch (\Throwable $e) {
                // Una web caída no tumba las demás: se cuenta y se sigue.
                $io->error(sprintf('%s: %s', $source->name(), $e->getMessage()));
                $failed = true;
                continue;
            }

            ksort($counts);
            $io->writeln('  ' . ($counts === [] ? 'nada' : implode(' · ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($counts), $counts))));
            // Para ver si la memoria sube de una fuente a otra: si lo hace, algo
            // se está quedando en memoria entre inserciones.
            $io->writeln(sprintf('  <comment>memoria: %d MB (pico %d MB)</comment>', intdiv(memory_get_usage(true), 1048576), intdiv(memory_get_peak_usage(true), 1048576)));
        }

        // Una ciudad mal escrita en el cron no puede pasar por «hoy no había
        // nada»: sin fuentes que lanzar, es un error.
        if ($matched === 0) {
            $io->error('Ninguna fuente coincide con --city / --source.');

            return Command::INVALID;
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private static function normalize(string $city): string
    {
        return strtr(mb_strtolower(trim($city)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }
}
