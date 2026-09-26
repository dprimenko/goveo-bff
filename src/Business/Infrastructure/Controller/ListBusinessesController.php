<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Controller;

use App\Badges\Domain\BadgeRepository;
use App\Business\Domain\Business;
use App\Business\Domain\BusinessRepository;
use App\Categories\Domain\Category;
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
 * Un **grupo** (`gastronomy`) incluye todo lo que cuelga de él: el negocio está
 * en la subcategoría, y quien filtra por el grupo espera verlos todos.
 *
 * `section=local|tourism` parte el listado en las dos pestañas de la home, sin
 * que el cliente tenga que saber qué categorías son de cuál (ver
 * `CategoryRepository::tourismIds`). Se combina con `category`.
 *
 * `badge=eco,terrace` (slug o id) deja sólo los que llevan todos esos badges.
 * Cada negocio devuelve los suyos en `badges`.
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
        private readonly BadgeRepository $badges,
    ) {}

    public function __invoke(Request $request): Response
    {
        $lat        = (float)  ($request->query->get('lat',      self::DEFAULT_LAT));
        $lng        = (float)  ($request->query->get('lng',      self::DEFAULT_LNG));
        $page       = max(1, (int) ($request->query->get('page', 1)));
        $size       = min(self::MAX_SIZE, max(1, (int) ($request->query->get('size', self::DEFAULT_SIZE))));
        $category    = $this->readCategories((string) $request->query->get('category', ''));
        $category    = $this->applySection($category, (string) $request->query->get('section', ''));
        $badgeIds    = $this->readBadges((string) $request->query->get('badge', ''));

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
            badgeIds:     $badgeIds,
        );

        $badges = $this->badges->forBusinesses(array_map(
            static fn (array $row) => $row['business']->getId(),
            $result['items'],
        ));

        $items = array_map(
            fn (array $row) => $this->serialize(
                $row['business'],
                $row['dist_meters'],
                $row['lat'],
                $row['long'],
            ) + ['badges' => $badges[$row['business']->getId()] ?? []],
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

            // La de antes, por la que ocupa su sitio (ver `BUSINESS_FILTER_ALIASES`).
            $alias = Category::BUSINESS_FILTER_ALIASES[$category?->getSlug() ?? ''] ?? null;
            if ($alias !== null) {
                $category = $this->categories->findBySlug($alias);
            }

            if ($category === null) {
                continue;
            }

            foreach ($this->categories->withDescendants([$category->getId()]) as $id) {
                if ($negated) {
                    $exclude[$id] = true;
                } else {
                    $include[$id] = true;
                }
            }
        }

        return [
            'include' => $include === [] ? null : array_keys($include),
            'exclude' => $exclude === [] ? null : array_keys($exclude),
        ];
    }

    /**
     * @param array{include: ?string[], exclude: ?string[]} $category
     * @return array{include: ?string[], exclude: ?string[]}
     */
    private function applySection(array $category, string $section): array
    {
        if (!in_array($section, [Category::SECTION_LOCAL, Category::SECTION_TOURISM], true)) {
            return $category;
        }

        $tourism = $this->categories->tourismIds();

        if ($section === Category::SECTION_LOCAL) {
            $category['exclude'] = array_values(array_unique([...($category['exclude'] ?? []), ...$tourism]));

            return $category;
        }

        // Turismo con una categoría encima: la intersección. Si no queda nada,
        // un id imposible y no `null`, que sería «sin filtro».
        $include = $category['include'] === null
            ? $tourism
            : array_values(array_intersect($category['include'], $tourism));
        $category['include'] = $include === [] ? ['00000000-0000-0000-0000-000000000000'] : $include;

        return $category;
    }

    /** @return ?string[] */
    private function readBadges(string $raw): ?array
    {
        $values = array_filter(array_map('trim', explode(',', $raw)));
        if ($values === []) {
            return null;
        }

        // Un badge desconocido no se ignora: pedir «terraza» y recibir de todo
        // sería peor que una lista vacía.
        return array_map(
            fn (string $v) => $this->badges->resolve($v) ?? '00000000-0000-0000-0000-000000000000',
            $values,
        );
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
