<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\GeoStories\Domain\GeoStoryLike;
use App\GeoStories\Domain\GeoStoryLikeRepository;
use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Infrastructure\Service\GeoStoryLikeCounter;
use App\Shared\Domain\UuidGenerator;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dar like a una geostory. Idempotente: repetirlo no suma dos veces.
 */
#[Route('/api/geostories/{id}/like', name: 'geostories_like', methods: ['POST'])]
class LikeGeoStoryController
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

        $story = $this->stories->findById($id);

        if ($story === null) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        if ($this->likes->find($userId, $id) === null) {
            $this->likes->save(new GeoStoryLike(UuidGenerator::generate(), $userId, $id));
        }

        return new JsonResponse([
            'liked' => true,
            'likes' => $this->counter->resolve($id, $story->getLikes()),
        ]);
    }
}
