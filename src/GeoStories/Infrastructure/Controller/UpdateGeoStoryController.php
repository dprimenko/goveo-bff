<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Infrastructure\Service\BunnyVideoService;
use App\GeoStories\Infrastructure\Service\GeoStoryOwnership;
use App\Security\GoveoUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Edit an existing GeoStory. Editable fields mirror the upload form: title,
 * description and — for influencer posts only — category + location (a business
 * post inherits both from the store, so they are not editable here). Optionally
 * overwrites the video: a new file is uploaded to Bunny (new GUID, status →
 * processing) and the previous Bunny video is deleted.
 *
 * Multipart POST (not PATCH) because PHP only parses multipart bodies for POST.
 */
#[Route('/api/geostories/{id}', name: 'geostories_update', methods: ['POST'])]
class UpdateGeoStoryController
{
    private const VIDEO_MIME = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm'];

    public function __construct(
        private readonly Security $security,
        private readonly GeoStoryRepository $geoStories,
        private readonly BunnyVideoService $bunny,
        private readonly GeoStoryOwnership $ownership,
    ) {}

    public function __invoke(string $id, Request $request): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof GoveoUser) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $story = $this->geoStories->findById($id);
        if ($story === null || $story->isDeleted()) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->ownership->userOwns($user, $story)) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $isBusiness = $story->getBusinessId() !== null;

        // ── Metadata ────────────────────────────────────────────────────────
        if ($request->request->has('title')) {
            $story->setTitle(trim((string) $request->request->get('title')) ?: null);
        }
        if ($request->request->has('description')) {
            $story->setDescription(trim((string) $request->request->get('description')) ?: null);
        }
        // Category + location are the store's for a business post — not editable.
        if (!$isBusiness) {
            $categoryId = $request->request->get('categoryId') ?: null;
            if ($categoryId !== null) {
                $story->setCategoryId($categoryId);
            }
            $lat = $request->request->get('lat');
            $lng = $request->request->get('lng');
            if ($lat !== null && $lng !== null && $lat !== '' && $lng !== '') {
                $story->setLocation((float) $lat, (float) $lng);
            }
        }

        // ── Optional video overwrite ────────────────────────────────────────
        /** @var UploadedFile|null $video */
        $video = $request->files->get('video');
        if ($video !== null) {
            if (!in_array($video->getMimeType(), self::VIDEO_MIME, true)) {
                return new JsonResponse(['error' => 'Unsupported video type'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
            }
            try {
                $uploaded = $this->bunny->uploadVideo($video, $story->getTitle() ?? 'GeoStory');
            } catch (\Throwable $e) {
                return new JsonResponse(['error' => 'Video upload failed', 'detail' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
            }
            $oldGuid = $story->getProviderVideoId();
            $story
                ->setUrl($uploaded['url'])
                ->setThumbnail($uploaded['thumbnail'])
                ->setProviderVideoId($uploaded['videoId'])
                ->markProcessing();
            if ($oldGuid !== null && $oldGuid !== $uploaded['videoId']) {
                $this->bunny->deleteVideo($oldGuid);
            }
        }

        $this->geoStories->save($story);

        return new JsonResponse([
            'id'            => $story->getId(),
            'title'         => $story->getTitle(),
            'description'   => $story->getDescription(),
            'url'           => $story->getUrl(),
            'thumbnail'     => $story->getThumbnail(),
            'status'        => $story->getStatus(),
            'influencer_id' => $story->getInfluencerId(),
            'business_id'   => $story->getBusinessId(),
            'category_id'   => $story->getCategoryId(),
        ]);
    }
}
