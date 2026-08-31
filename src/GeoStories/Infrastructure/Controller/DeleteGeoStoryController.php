<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Infrastructure\Service\BunnyVideoService;
use App\GeoStories\Infrastructure\Service\GeoStoryOwnership;
use App\Security\GoveoUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Soft-delete a GeoStory (sets deleted_at → hidden from every feed) and remove
 * its Bunny video to avoid orphans. Owner-only (influencer's own post or a
 * managed store's post).
 */
#[Route('/api/geostories/{id}', name: 'geostories_delete', methods: ['DELETE'])]
class DeleteGeoStoryController
{
    public function __construct(
        private readonly Security $security,
        private readonly GeoStoryRepository $geoStories,
        private readonly BunnyVideoService $bunny,
        private readonly GeoStoryOwnership $ownership,
    ) {}

    public function __invoke(string $id): Response
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

        $guid = $story->getProviderVideoId();
        $story->softDelete();
        $this->geoStories->save($story);

        if ($guid !== null) {
            $this->bunny->deleteVideo($guid);
        }

        return new JsonResponse(['id' => $id, 'deleted' => true]);
    }
}
