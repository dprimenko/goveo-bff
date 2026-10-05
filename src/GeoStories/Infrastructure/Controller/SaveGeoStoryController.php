<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Domain\SavedGeoStoryRepository;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Guardar un vídeo («Guardados»). Idempotente: repetirlo no duplica nada.
 *
 * Con `requirements` en el id: uno que no es UUID haría fallar la consulta con
 * un 500, y así es un 404 de rutas.
 */
#[Route('/api/geostories/{id}/save', name: 'geostories_save', methods: ['PUT'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
class SaveGeoStoryController
{
    public function __construct(
        private readonly SavedGeoStoryRepository $saved,
        private readonly GeoStoryRepository $stories,
        private readonly LocalUserResolver $currentUser,
    ) {}

    public function __invoke(string $id): Response
    {
        $userId = $this->currentUser->currentId();

        if ($userId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $story = $this->stories->findById($id);

        if ($story === null || $story->getDeletedAt() !== null) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $this->saved->save($userId, $id);

        return new JsonResponse(['saved' => true]);
    }
}
