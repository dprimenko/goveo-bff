<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Controller;

use App\Business\Domain\Business;
use App\Business\Domain\BusinessRepository;
use App\Categories\Domain\CategoryRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /public/businesses?lat=&lng=&page=&size=&category=&radius=&q=
 *
 * `category` admite **varias separadas por coma**, y cada una puede ser el id o
 * el slug. Varias porque la home agrupa —«comercio local» son casi treinta
 * categorías— y con una sola habría que pedir una página por categoría y
 * mezclarlas en el cliente, perdiendo el orden por cercanía. Por slug porque
 * así el grupo se escribe legible («hotels,boats,excursions») en vez de con
 * una lista de uuids que nadie puede revisar. Un valor con `-` delante
 * **excluye** en vez de incluir (`category=-hotels,-boats`): la home necesita
 * «todo lo que no es turismo», y enumerarlo serían cuarenta y tantos slugs en
 * la URL que además habría que mantener al día cada vez que nace una categoría.
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
        private readonly CategoryRepository $categories,
    ) {}

    public function __invoke(Request $request): Response
    {
        $lat        = (float)  ($request->query->get('lat',      self::DEFAULT_LAT));
        $lng        = (float)  ($request->query->get('lng',      self::DEFAULT_LNG));
        $page       = max(1, (int) ($request->query->get('page', 1)));
        $size       = min(self::MAX_SIZE, max(1, (int) ($request->query->get('size', self::DEFAULT_SIZE))));
        $category    = $this->readCategories((string) $request->query->get('category', ''));

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
            categoryIds:  $category['include'],
            excludeCategoryIds: $category['exclude'],
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

    /**
     * Las categorías pedidas, repartidas entre las que incluyen y las que
     * excluyen (las que llevan `-` delante). Cada una puede ser id o slug.
     *
     * Lo que no existe se descarta en silencio en vez de vaciar el listado: un
     * slug que ya no está —renombrado, borrado— dejaría la home sin nada y sin
     * explicar por qué. Un lado vacío es `null`, que es «sin filtro por ahí».
     *
     * @return array{include: ?string[], exclude: ?string[]}
     */
    private function readCategories(string $raw): array
    {
        $include = [];
        $exclude = [];

        foreach (array_filter(array_map('trim', explode(',', $raw))) as $value) {
            $negated = str_starts_with($value, '-');
            $value   = ltrim($value, '-');

            // Se mira la forma antes de preguntar: la columna es `uuid` y
            // buscar un slug por id revienta la consulta en Postgres en vez de
            // devolver «no encontrado».
            $category = preg_match('/^[0-9a-f-]{36}$/i', $value)
                ? $this->categories->findById($value)
                : $this->categories->findBySlug($value);

            if ($category === null) {
                continue;
            }

            if ($negated) {
                $exclude[$category->getId()] = true;
            } else {
                $include[$category->getId()] = true;
            }
        }

        return [
            'include' => $include === [] ? null : array_keys($include),
            'exclude' => $exclude === [] ? null : array_keys($exclude),
        ];
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
