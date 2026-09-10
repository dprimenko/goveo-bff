<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Infrastructure\Service\BunnyVideoService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * DELETE /api/admin/geostories/{id}
 *
 * Borra el vídeo **de verdad**: la fila, sus likes y el fichero en Bunny. No hay
 * vuelta atrás, y por eso:
 *
 * - Va detrás de su propio permiso (`geostory.delete`) y no del de moderar.
 *   Quien decide qué se ve y quien puede destruir no tienen por qué ser la misma
 *   persona.
 * - **Sólo sobre lo ya borrado.** Un vídeo vivo se retira primero —eso es
 *   reversible— y se destruye después, si acaso. Así ningún clic de más acaba en
 *   algo irrecuperable.
 *
 * Lo que se recupera al borrar en Bunny es almacenamiento que se está pagando
 * por un vídeo que ya no ve nadie; ése es el motivo de que exista esta opción y
 * no simplemente dejar la fila marcada para siempre.
 */
#[Route('/api/admin/geostories/{id}', name: 'admin_geostory_purge', methods: ['DELETE'])]
#[IsGranted('ROLE_GEOSTORY_DELETE')]
class PurgeGeoStoryController
{
    public function __construct(
        private readonly GeoStoryRepository $geoStories,
        private readonly BunnyVideoService $bunny,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(string $id): Response
    {
        $story = $this->geoStories->findById($id);

        if ($story === null) {
            return new JsonResponse(['error' => 'GeoStory not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$story->isDeleted()) {
            return new JsonResponse(
                ['error' => 'Only already-deleted geostories can be purged.'],
                Response::HTTP_CONFLICT,
            );
        }

        $providerVideoId = $story->getProviderVideoId();

        // Primero Bunny: si fallara después el borrado de la fila, quedaría una
        // ficha apuntando a un vídeo que ya no existe, que es un estado del que
        // se puede salir. Al revés quedaría un vídeo pagándose en Bunny sin nada
        // que lo nombre, y ése ya no lo encuentra nadie.
        if ($providerVideoId !== null && $providerVideoId !== '') {
            // `deleteVideo` se traga sus propios errores y los deja en el
            // registro: un fallo suyo no debe impedir limpiar la base.
            $this->bunny->deleteVideo($providerVideoId);
        }

        // Los likes no tienen clave ajena declarada, así que no se van solos.
        $this->db->executeStatement('DELETE FROM geostory_likes WHERE geostory_id = ?', [$id]);

        $this->geoStories->delete($story);

        $this->logger->info('Vídeo borrado definitivamente desde el panel', [
            'geostory' => $id,
            'bunny'    => $providerVideoId,
        ]);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
