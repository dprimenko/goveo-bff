<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\GeoStories\Domain\GeoStoryLikeRepository;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ids de las geostories que el usuario ha likeado. La app lo carga una vez y
 * con eso pinta el corazón de cada tarjeta del feed sin llamadas por vídeo.
 */
#[Route('/api/geostories/likes', name: 'geostories_likes_mine', methods: ['GET'])]
class ListMyLikesController
{
    public function __construct(
        private readonly GeoStoryLikeRepository $likes,
        private readonly LocalUserResolver $currentUser,
    ) {}

    public function __invoke(): Response
    {
        $userId = $this->currentUser->currentId();

        if ($userId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(['geostories' => $this->likes->findIdsByUser($userId)]);
    }
}
