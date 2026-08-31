<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Controller;

use App\Business\Domain\Business;
use App\Business\Domain\BusinessRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /public/businesses?lat=&lng=&page=&size=&category=&radius=&q=
 *
 * Returns businesses ordered by proximity to the given coordinates.
 * `radius` (metros) acota el resultado: lo usa el mapa para pedir sólo los
 * negocios de la zona visible en vez de traerlos todos por cercanía.
 * `q` filtra por nombre (sin tildes ni mayúsculas) manteniendo el orden por
 * cercanía: buscando, lo de al lado interesa más que lo de la otra punta.
 * Requires the `location` geometry(POINT,4326) column on the business table.
 */
#[Route('/public/businesses', name: 'pub_businesses_list', methods: ['GET'])]
class ListBusinessesController
{
    private const DEFAULT_LAT  = 41.3873974;
    private const DEFAULT_LNG  = 2.168568;
    private const DEFAULT_SIZE = 20;
    private const MAX_SIZE     = 100;
    /** ~medio planeta: por encima de esto el radio deja de acotar nada. */
    private const MAX_RADIUS_M = 500_000;

    public function __construct(
        private readonly BusinessRepository $repository,
    ) {}

    public function __invoke(Request $request): Response
    {
        $lat        = (float)  ($request->query->get('lat',      self::DEFAULT_LAT));
        $lng        = (float)  ($request->query->get('lng',      self::DEFAULT_LNG));
        $page       = max(1, (int) ($request->query->get('page', 1)));
        $size       = min(self::MAX_SIZE, max(1, (int) ($request->query->get('size', self::DEFAULT_SIZE))));
        $categoryId = $request->query->get('category');

        $rawRadius = $request->query->get('radius');
        $radius    = $rawRadius === null || $rawRadius === ''
            ? null
            : min(self::MAX_RADIUS_M, max(1.0, (float) $rawRadius));

        $query = trim((string) $request->query->get('q', ''));

        $result = $this->repository->findNearby(
            latitude:     $lat,
            longitude:    $lng,
            page:         $page,
            size:         $size,
            categoryId:   $categoryId ?: null,
            radiusMeters: $radius,
            query:        $query !== '' ? $query : null,
        );

        $items = array_map(
            fn (array $row) => $this->serialize(
                $row['business'],
                $row['dist_meters'],
                $row['lat'],
                $row['long'],
            ),
            $result['items'],
        );

        return new JsonResponse([
            'items' => $items,
            'total' => $result['total'],
            'page'  => $page,
            'size'  => $size,
        ]);
    }

    private function serialize(
        Business $b,
        float $distMeters,
        float $lat,
        float $lng,
    ): array {
        $meta = $b->getMeta() ?? [];

        return [
            'id'          => $b->getId(),
            'slug'        => $b->getSlug(),
            'name'        => $b->getName(),
            'avatar'      => $b->getAvatar(),
            // Portada para la tarjeta del mapa (el avatar solo queda pobre).
            'main_image'  => $b->getMainImage(),
            'category_id' => $b->getCategoryId(),
            'address'     => $meta['address'] ?? null,
            'dist_meters' => (int) round($distMeters),
            // Coordenadas para los marcadores del mapa.
            'lat'         => $lat,
            'lng'         => $lng,
        ];
    }
}
