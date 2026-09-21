<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\Backoffice\Application\ReviewQueueNotifier;
use App\GeoStories\Domain\GeoStory;
use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Infrastructure\Service\BunnyVideoService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bunny Stream transcoding webhook. Configure this URL in the Bunny library
 * (append ?secret=<BUNNY_WEBHOOK_SECRET>). Bunny POSTs
 * `{ VideoLibraryId, VideoGuid, Status }` — y nada más: **no manda `EventType`**.
 *
 * ⚠️ El `Status` del aviso **no es el mismo número que el del vídeo** en la API
 * (donde 4 = terminado). En el webhook:
 *
 * | Status | Qué es |
 * |---|---|
 * | 0,1,2 | en cola, preparando, codificando |
 * | 3 | codificación terminada |
 * | 4 | una resolución lista (aquí ya se puede ver) |
 * | 5 | ha fallado |
 * | 6,7,8 | subida pre-firmada (empezada, hecha, fallida) |
 * | 9,10 | subtítulos / título automáticos |
 *
 * Los avisos llegan en ese orden, así que **el 3 llega después del 4**: se
 * tomaba sólo el 4 por bueno y el 3 caía en «cualquier otra cosa», que marcaba
 * `processing`. Resultado: el vídeo quedaba listo un instante y el siguiente
 * aviso lo devolvía a «procesando» para siempre —en Bunny terminado hace días y
 * en el panel procesando—. Por eso aquí un vídeo que ya está listo no vuelve
 * atrás: lo contrario sólo puede llegar de un `failed`.
 *
 * Path is under /api/v1/webhooks → PUBLIC_ACCESS (see security.yaml).
 * Always returns 200 so Bunny does not retry on unknown videos.
 */
#[Route('/api/v1/webhooks/bunny/video-status', name: 'bunny_webhook_video_status', methods: ['POST'])]
class BunnyWebhookController
{
    /** Codificación terminada (3) y primera resolución lista (4): ya se ve. */
    private const STATUSES_READY = [3, 4];
    /** Codificación fallida (5) y subida pre-firmada fallida (8). */
    private const STATUSES_FAILED = [5, 8];
    /** En cola, preparando, codificando. Lo único que es «todavía no». */
    private const STATUSES_IN_FLIGHT = [0, 1, 2];

    public function __construct(
        private readonly GeoStoryRepository $geoStories,
        private readonly ReviewQueueNotifier $reviewQueue,
        private readonly BunnyVideoService $bunny,
        private readonly LoggerInterface $logger,
        private readonly string $webhookSecret,
    ) {}

    public function __invoke(Request $request): Response
    {
        // Shared-secret check (secret is carried in the URL query string).
        if ($this->webhookSecret !== '' && $request->query->get('secret') !== $this->webhookSecret) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        $videoGuid = $payload['VideoGuid'] ?? null;
        $status    = isset($payload['Status']) ? (int) $payload['Status'] : null;

        if (!is_string($videoGuid) || $videoGuid === '') {
            return new JsonResponse(['error' => 'Missing VideoGuid'], Response::HTTP_BAD_REQUEST);
        }

        $geoStory = $this->geoStories->findByProviderVideoId($videoGuid);
        if ($geoStory === null) {
            // Unknown video — ack so Bunny stops retrying.
            $this->logger->info('Bunny webhook: geostory not found', ['guid' => $videoGuid]);
            return new JsonResponse(['message' => 'Video not found'], Response::HTTP_OK);
        }

        $ready    = in_array($status, self::STATUSES_READY, true);
        $failed   = in_array($status, self::STATUSES_FAILED, true);
        $wasReady = $geoStory->getStatus() === GeoStory::STATUS_READY;

        if ($ready && $wasReady) {
            // Ya estaba listo: nada que tocar. Se sale antes de preguntar a
            // Bunny por la calidad, que es una petición HTTP por aviso y de
            // estos llegan varios por vídeo.
            return new JsonResponse(['id' => $geoStory->getId(), 'status' => $geoStory->getStatus()]);
        }

        if ($ready) {
            // La URL definitiva no se sabe hasta aquí: al subir se guarda la de
            // 720p a ciegas y Bunny no genera esa calidad si el original no da
            // para tanto. Ver BunnyVideoService::getBestVideoUrl.
            $geoStory->setUrl($this->bunny->getBestVideoUrl($videoGuid));
            $geoStory->markReady();
        } elseif ($failed) {
            $geoStory->markFailed();
        } elseif (in_array($status, self::STATUSES_IN_FLIGHT, true) && !$wasReady) {
            $geoStory->markProcessing();
        } else {
            // Subtítulos, título automático, avisos de subida… no dicen nada de
            // la codificación. Antes caían aquí y marcaban «procesando» un vídeo
            // que ya se veía.
            return new JsonResponse(['id' => $geoStory->getId(), 'status' => $geoStory->getStatus()]);
        }

        $this->geoStories->save($geoStory);

        // Sólo al pasar de «procesando» a listo: Bunny manda varios avisos por
        // vídeo, y sin esto cada uno sería otro correo. El resto de condiciones
        // las pone el notificador.
        if ($ready && !$wasReady) {
            $this->reviewQueue->geoStoryPendingReview($geoStory);
        }

        return new JsonResponse([
            'id'     => $geoStory->getId(),
            'status' => $geoStory->getStatus(),
        ]);
    }
}
