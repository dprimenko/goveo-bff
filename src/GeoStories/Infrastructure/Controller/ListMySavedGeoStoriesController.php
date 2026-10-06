<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Domain\GeoStoryWithDistance;
use App\GeoStories\Infrastructure\Service\GeoStoryFeedSerializer;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «Guardados»: los vídeos que ha guardado el usuario, el último primero, con
 * la misma forma que `/public/geostories` para que la app use el mismo mapper.
 * `lat`/`lng` sólo sirven para la distancia de cada tarjeta.
 * `feedType` (`events`, `local`, `tourism`, `geostories`) deja sólo los de esa
 * pestaña, con el mismo corte que el feed: son las pestañas de Guardados.
 *
 * → {items: [...], total}
 */
#[Route('/api/geostories/saved', name: 'geostories_saved', methods: ['GET'])]
class ListMySavedGeoStoriesController
{
    // Los mismos que el feed, para que la distancia salga igual sin ubicación.
    private const DEFAULT_LAT  = 41.3873974;
    private const DEFAULT_LONG = 2.168568;

    public function __construct(
        private readonly GeoStoryRepository $repository,
        private readonly LocalUserResolver $currentUser,
    ) {}

    public function __invoke(Request $request): Response
    {
        $userId = $this->currentUser->currentId();

        if ($userId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $result = $this->repository->findSavedBy(
            userId:    $userId,
            latitude:  (float) $request->query->get('lat', self::DEFAULT_LAT),
            longitude: (float) $request->query->get('lng', self::DEFAULT_LONG),
            page:      max(0, (int) $request->query->get('page', 0)),
            size:      max(1, min((int) $request->query->get('size', 10), 100)),
            feedType:  $request->query->get('feedType') ?: null,
        );

        return new JsonResponse([
            'items' => array_map(
                fn (GeoStoryWithDistance $s) => GeoStoryFeedSerializer::serialize($s),
                $result['items'],
            ),
            'total' => $result['total'],
        ]);
    }
}
