<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Command;

use App\Backoffice\Application\ReviewQueueNotifier;
use App\GeoStories\Domain\GeoStory;
use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Infrastructure\Service\BunnyVideoService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Desatasca los vídeos que dicen «procesando» y en Bunny llevan días listos.
 *
 * El estado lo pone el webhook, y hasta ahora el aviso de «codificación
 * terminada» (Status 3) caía en el cajón de «todavía no» y devolvía a
 * `processing` un vídeo que ya estaba listo — ver `BunnyWebhookController`.
 * Arreglado el webhook, los que se quedaron atrás siguen atascados: nada los
 * vuelve a mirar salvo que su dueño abra su perfil en la app, que es donde vive
 * el único repaso que había (`ListGeoStoriesController::reconcileProcessing`).
 *
 * En el panel no había ninguno, y es justo donde se ven: la cola de validación
 * lee `status` de la base tal cual.
 *
 *   goveo:geostories:reconcile              # dice qué cambiaría
 *   goveo:geostories:reconcile --apply      # lo cambia
 *
 * ⚠️ El número que devuelve la API del vídeo **no es el del webhook**: aquí
 * 4 = terminado y 5 = error, que es el que lee `BunnyVideoService::getVideoStatus`.
 *
 * Los importados de la librería vieja no tienen `provider_video_id` o viven en
 * otra librería, así que Bunny no sabe de ellos: se cuentan aparte y se dejan
 * como están, que es mejor que marcarlos fallidos por no encontrarlos.
 */
#[AsCommand(
    name: 'goveo:geostories:reconcile',
    description: 'Pone al día los vídeos atascados en «procesando» preguntando a Bunny.',
)]
final class ReconcileProcessingGeoStoriesCommand extends Command
{
    /** Estado del **objeto vídeo** en la API de Bunny (no el del webhook). */
    private const BUNNY_FINISHED = 4;
    private const BUNNY_FAILED   = 5;

    public function __construct(
        private readonly GeoStoryRepository $geoStories,
        private readonly BunnyVideoService $bunny,
        private readonly ReviewQueueNotifier $reviewQueue,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Guarda los cambios (sin esto sólo los enseña).')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Cuántos vídeos revisar como mucho.', '200')
            // Repasar un atasco viejo mandaría un correo por vídeo de golpe, y
            // eso no es avisar de nada: es vaciar la cola en la bandeja de
            // entrada. Para el repaso de hoy se apaga; en el uso normal —uno
            // que se perdió su aviso— interesa que salga.
            ->addOption('no-notify', null, InputOption::VALUE_NONE, 'No manda el aviso de «vídeo por validar».');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $apply  = (bool) $input->getOption('apply');
        $limit  = max(1, (int) $input->getOption('limit'));
        $avisar = !$input->getOption('no-notify');

        $atascados = $this->geoStories->findStuckProcessing($limit);

        if ($atascados === []) {
            $io->success('Ningún vídeo en «procesando».');

            return Command::SUCCESS;
        }

        $io->text(sprintf('%d vídeos en «procesando».', count($atascados)));

        $listos        = 0;
        $fallidos      = 0;
        $enCurso       = 0;
        $ilocalizables = 0;
        $filas         = [];

        foreach ($atascados as $story) {
            $guid   = (string) $story->getProviderVideoId();
            $estado = $this->bunny->getVideoStatus($guid);

            if ($estado === null) {
                ++$ilocalizables;
                continue;
            }

            if ($estado === self::BUNNY_FINISHED) {
                ++$listos;
                $filas[] = [$story->getId(), $this->cuando($story), 'listo'];

                if ($apply) {
                    // Igual que el webhook: la calidad real no se sabe hasta que
                    // Bunny termina. Ver BunnyVideoService::getBestVideoUrl.
                    $story->setUrl($this->bunny->getBestVideoUrl($guid));
                    $story->markReady();
                    $this->geoStories->save($story);

                    // Su dueño subió el vídeo y nadie llegó a enterarse de que
                    // estaba listo para revisar: el aviso lo manda quien lo
                    // descubre, y aquí eso es este comando.
                    if ($avisar) {
                        $this->reviewQueue->geoStoryPendingReview($story);
                    }
                }
            } elseif ($estado === self::BUNNY_FAILED) {
                ++$fallidos;
                $filas[] = [$story->getId(), $this->cuando($story), 'fallido'];

                if ($apply) {
                    $story->markFailed();
                    $this->geoStories->save($story);
                }
            } else {
                // Codificando de verdad: son los recién subidos, y ésos están
                // bien donde están.
                ++$enCurso;
            }
        }

        if ($filas !== []) {
            $io->table(['id', 'subido', 'pasa a'], $filas);
        }

        $io->text(sprintf(
            'listos: %d · fallidos: %d · aún codificando: %d · sin rastro en Bunny: %d',
            $listos,
            $fallidos,
            $enCurso,
            $ilocalizables,
        ));

        if (!$apply && ($listos > 0 || $fallidos > 0)) {
            $io->warning('En seco: nada se ha guardado. Repite con --apply.');

            return Command::SUCCESS;
        }

        $io->success($apply ? 'Estados puestos al día.' : 'Nada que cambiar.');

        return Command::SUCCESS;
    }

    private function cuando(GeoStory $story): string
    {
        return $story->getCreatedAt()->format('Y-m-d H:i');
    }
}
