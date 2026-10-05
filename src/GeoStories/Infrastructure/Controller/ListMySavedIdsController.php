<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\GeoStories\Domain\SavedGeoStoryRepository;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ids de los vídeos que el usuario ha guardado. Como los likes: la app lo carga
 * una vez y pinta el marcador de cada tarjeta sin una llamada por vídeo.
 */
#[Route('/api/geostories/saved/ids', name: 'geostories_saved_ids', methods: ['GET'])]
class ListMySavedIdsController
{
    public function __construct(
        private readonly SavedGeoStoryRepository $saved,
        private readonly LocalUserResolver $currentUser,
    ) {}

    public function __invoke(): Response
    {
        $userId = $this->currentUser->currentId();

        if ($userId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(['ids' => $this->saved->findIdsByUser($userId)]);
    }
}
