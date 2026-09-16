<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Command;

use App\GeoStories\Infrastructure\Service\BunnyVideoService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repara los enlaces de vídeo que apuntan a una calidad que no existe.
 *
 * Todas las URLs se guardaron como `play_720p.mp4` sin comprobar nada, y Bunny
 * no genera esa calidad cuando el original no da para tanto: un vídeo grabado
 * a 854 de lado largo sólo tiene 480p, y su enlace devuelve **404**. A partir
 * de ahora se corrige solo al terminar de codificar (ver
 * `BunnyVideoService::getBestVideoUrl`), pero lo ya guardado hay que repasarlo.
 *
 *   goveo:geostories:fix-video-urls              # dice qué cambiaría
 *   goveo:geostories:fix-video-urls --apply      # lo cambia
 *
 * Va por defecto en seco porque toca la columna que decide qué se reproduce:
 * si el máster HLS no se pudiera leer, guardar «lo que haya salido» dejaría los
 * vídeos peor que antes. Por eso los que no responden se cuentan y se saltan.
 */
#[AsCommand(
    name: 'goveo:geostories:fix-video-urls',
    description: 'Apunta cada vídeo a la mejor calidad que Bunny haya generado.',
)]
final class FixVideoUrlsCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly BunnyVideoService $bunny,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Guarda los cambios (sin esto sólo los enseña).')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Cuántos vídeos revisar como mucho.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $limit = $input->getOption('limit');

        $sql = "SELECT id, url FROM geostories WHERE deleted_at IS NULL AND url ~ '/play_[0-9]+p\\.mp4$' ORDER BY created_at DESC";
        if ($limit !== null) {
            $sql .= sprintf(' LIMIT %d', (int) $limit);
        }
        $rows = $this->db->fetchAllAssociative($sql);

        $io->text(sprintf('%d vídeos con URL de calidad fija.', count($rows)));

        $cambiados = 0;
        $ilegibles = 0;
        foreach ($rows as $row) {
            // El GUID va en la propia URL: los vídeos importados no tienen
            // `provider_video_id`, y son justo los que más falta hace repasar.
            // El servidor también: los vídeos importados viven en otra
            // librería de Bunny, no en la que dice el entorno.
            if (!preg_match('~^https://([^/]+)/([0-9a-f-]{36})/play_(\d+p)\.mp4$~i', (string) $row['url'], $m)) {
                continue;
            }
            [$_, $host, $videoId, $actual] = $m;

            $disponibles = $this->bunny->availableResolutions($videoId, $host);
            if ($disponibles === []) {
                ++$ilegibles;
                continue;
            }
            // Sólo se tocan los rotos. Que un vídeo tenga 1080p no es motivo
            // para cambiarle el enlace: el que tiene funciona, y subirlo sería
            // servir más ancho de banda por la cara.
            if (in_array((int) $actual, $disponibles, true)) {
                continue;
            }
            $mejor = $this->bunny->bestResolution($videoId, $host);
            if ($mejor === null) {
                ++$ilegibles;
                continue;
            }

            ++$cambiados;
            $io->text(sprintf('  %s  %s → %s', $videoId, $actual, $mejor));

            if ($apply) {
                $this->db->executeStatement(
                    'UPDATE geostories SET url = ?, updated_at = now() WHERE id = ?',
                    [sprintf('https://%s/%s/play_%s.mp4', $host, $videoId, $mejor), $row['id']],
                );
            }
        }

        if ($ilegibles > 0) {
            $io->warning(sprintf('%d vídeos sin máster HLS legible: se quedan como estaban.', $ilegibles));
        }

        $io->success($apply
            ? sprintf('%d vídeos actualizados.', $cambiados)
            : sprintf('%d vídeos cambiarían. Repite con --apply para guardarlo.', $cambiados));

        return Command::SUCCESS;
    }
}
