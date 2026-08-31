<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\GeoStories\Domain\GeoStoryRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bunny Stream transcoding webhook. Configure this URL in the Bunny library
 * (append ?secret=<BUNNY_WEBHOOK_SECRET>). Bunny POSTs { VideoGuid, Status,
 * EventType }; we map it to the GeoStory transcoding status.
 *
 * Path is under /api/v1/webhooks → PUBLIC_ACCESS (see security.yaml).
 * Always returns 200 so Bunny does not retry on unknown videos.
 */
#[Route('/api/v1/webhooks/bunny/video-status', name: 'bunny_webhook_video_status', methods: ['POST'])]
class BunnyWebhookController
{
    // Bunny numeric statuses: 0-3 in-flight, 4 finished, 5 failed.
    private const STATUS_FINISHED = 4;
    private const STATUS_FAILED   = 5;

    public function __construct(
        private readonly GeoStoryRepository $geoStories,
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
        $eventType = $payload['EventType'] ?? null;

        if (!is_string($videoGuid) || $videoGuid === '') {
            return new JsonResponse(['error' => 'Missing VideoGuid'], Response::HTTP_BAD_REQUEST);
        }

        $geoStory = $this->geoStories->findByProviderVideoId($videoGuid);
        if ($geoStory === null) {
            // Unknown video — ack so Bunny stops retrying.
            $this->logger->info('Bunny webhook: geostory not found', ['guid' => $videoGuid]);
            return new JsonResponse(['message' => 'Video not found'], Response::HTTP_OK);
        }

        $ready  = $eventType === 'video.encoded' || $status === self::STATUS_FINISHED;
        $failed = $eventType === 'video.failed'  || $status === self::STATUS_FAILED;

        if ($ready) {
            $geoStory->markReady();
        } elseif ($failed) {
            $geoStory->markFailed();
        } else {
            $geoStory->markProcessing();
        }
        $this->geoStories->save($geoStory);

        return new JsonResponse([
            'id'     => $geoStory->getId(),
            'status' => $geoStory->getStatus(),
        ]);
    }
}
