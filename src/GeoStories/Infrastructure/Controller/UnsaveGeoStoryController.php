<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\GeoStories\Domain\SavedGeoStoryRepository;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Quitar un vídeo de Guardados. Idempotente, y sin 404 aunque el vídeo ya no
 * exista: es justo lo que hace falta para limpiar un guardado que se quedó
 * apuntando a algo borrado.
 */
#[Route('/api/geostories/{id}/save', name: 'geostories_unsave', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
class UnsaveGeoStoryController
{
    public function __construct(
        private readonly SavedGeoStoryRepository $saved,
        private readonly LocalUserResolver $currentUser,
    ) {}

    public function __invoke(string $id): Response
    {
        $userId = $this->currentUser->currentId();

        if ($userId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $this->saved->remove($userId, $id);

        return new JsonResponse(['saved' => false]);
    }
}
