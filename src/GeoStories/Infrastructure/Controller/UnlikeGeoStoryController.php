<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\GeoStories\Domain\GeoStoryLikeRepository;
use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Infrastructure\Service\GeoStoryLikeCounter;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Quitar el like. Idempotente igual que el POST.
 */
#[Route('/api/geostories/{id}/like', name: 'geostories_unlike', methods: ['DELETE'])]
class UnlikeGeoStoryController
{
    public function __construct(
        private readonly GeoStoryLikeRepository $likes,
        private readonly GeoStoryRepository $stories,
        private readonly GeoStoryLikeCounter $counter,
        private readonly LocalUserResolver $currentUser,
    ) {}

    public function __invoke(string $id): Response
    {
        $userId = $this->currentUser->currentId();

        if ($userId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $like = $this->likes->find($userId, $id);

        if ($like !== null) {
            $this->likes->delete($like);
        }

        return new JsonResponse([
            'liked' => false,
            'likes' => $this->counter->resolve($id, $this->stories->findById($id)?->getLikes()),
        ]);
    }
}
