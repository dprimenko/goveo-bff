<?php

declare(strict_types=1);

namespace App\Categories\Infrastructure\Controller;

use App\Categories\Domain\Category;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /public/categories
 *
 * El catálogo es un árbol: **grupos** (Gastronomía, Alojamientos…, con
 * `section` local o tourism) de los que cuelgan subcategorías, más las de
 * primer nivel que no se reorganizaron (las de influencer, Comercio
 * Centenario) y los tipos de evento bajo `events`.
 *
 * - Sin parámetros: el primer nivel — grupos y el resto de sueltas. Las
 *   subcategorías colarían «Flamenco» o «Bares y Tapas» entre los círculos.
 * - `?section=local|tourism`: sólo los grupos de esa sección.
 * - `?tree=1`: cada una con sus `children` **encendidas** (`active`) y los
 *   `badges` que ofrece como filtro (`badge_categories`: Ecológico y Terraza,
 *   sólo en Gastronomía). Es lo que pinta la home: grupos arriba, subcategorías
 *   y badges al elegir uno.
 * - `?with_businesses=1`: quita lo que no tiene ningún negocio publicado (un
 *   grupo cuenta los de sus subcategorías) y añade `business_count`. Para el
 *   público, que no tiene que ver chips que llevan a una lista vacía. Con él,
 *   las `children` van **por volumen**, de más a menos.
 * - `?parent=<slug|id>`: las hijas encendidas de una (los tipos de evento).
 * - `?mode=business|influencer`: las que se pueden **elegir** al dar de alta un
 *   negocio o subir un vídeo — las hojas, no los grupos, estén encendidas o no:
 *   que una subcategoría no se enseñe todavía no impide usarla. Cada una trae
 *   `parent_id` para agruparlas en el selector.
 * - `?types=` y `?partner=` como siempre (ibiza tiene catálogo propio). Con
 *   `types` y sin `section` ni `mode` es **la petición de la app publicada**
 *   antes de los grupos (sus círculos de Comercio local y el mapa). Qué ve la
 *   decide `CATEGORIES_LEGACY_LEAVES`: apagado (por defecto), los grupos
 *   —filtra por grupo entero, no sabe bajar a subcategorías—; encendido, las
 *   categorías de siempre, las hojas con imagen (las subcategorías nuevas, sin
 *   imagen, serían círculos vacíos).
 */
#[Route('/public/categories', name: 'pub_categories_')]
class ListCategoriesController
{
    private const COLUMNS = 'c.id::text AS id, c.slug, c.name, c.image, c."order", c.mode,
                             c.parent_id::text AS parent_id, c.section, c.active';

    public function __construct(
        private readonly Connection $db,
        private readonly bool $legacyLeaves = false,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $withBusinesses = $request->query->getBoolean('with_businesses');
        $counts = $withBusinesses ? $this->businessCounts() : null;

        $parent = trim($request->query->getString('parent', ''));
        if ($parent !== '') {
            $rows = $this->db->fetchAllAssociative(
                'SELECT ' . self::COLUMNS . ' FROM categories c
                   JOIN categories p ON p.id = c.parent_id
                  WHERE c.deleted_at IS NULL AND c.active AND (p.slug = ? OR p.id::text = ?)
                  ORDER BY c."order" ASC',
                [$parent, $parent],
            );

            return $this->respond($rows, $counts);
        }

        // ?partner=xxx → las de ese partner; sin él, el catálogo general.
        $partner = $request->query->has('partner') ? (string) $request->query->get('partner') : null;
        $where   = [$partner === null ? 'c.partner IS NULL' : 'c.partner = :partner'];
        $params  = $partner === null ? [] : ['partner' => $partner];
        $types   = [];

        $mode    = (string) $request->query->get('mode', '');
        $section = (string) $request->query->get('section', '');
        $typeIds = array_values(array_filter(explode(',', $request->query->getString('types', ''))));
        $legacy  = $this->legacyLeaves && $typeIds !== [] && $mode === '' && $section === '';

        if ($legacy) {
            $where[] = 'c.section IS NULL';
            $where[] = '(c.parent_id IS NULL OR g.section IS NOT NULL)';
            $where[] = 'c.active';
            // Un grupo oculto se lleva sus categorías también aquí.
            $where[] = '(g.id IS NULL OR g.active)';
            $where[] = 'c.image IS NOT NULL';
        } elseif ($mode !== '') {
            // Lo elegible: hojas de un grupo o sueltas de primer nivel. Ni los
            // grupos (un negocio va en una subcategoría) ni los tipos de
            // evento (van aparte, en `subcategory_id` del vídeo).
            $where[] = 'c.section IS NULL';
            $where[] = '(c.parent_id IS NULL OR g.section IS NOT NULL)';
            $where[] = 'c.mode IN (:modes)';
            $params['modes'] = [$mode, Category::MODE_BOTH];
            $types['modes']  = ArrayParameterType::STRING;
        } else {
            $where[] = 'c.parent_id IS NULL';
            $where[] = 'c.active';
        }

        if (in_array($section, [Category::SECTION_LOCAL, Category::SECTION_TOURISM], true)) {
            $where[] = 'c.section = :section';
            $params['section'] = $section;
        }

        // ?types=uuid1,uuid2 → por tipo de categoría (pivote categories_category_types).
        if ($typeIds !== []) {
            $where[] = 'EXISTS (SELECT 1 FROM categories_category_types cct
                                 WHERE cct.category_id = c.id AND cct.type_id::text IN (:types))';
            $params['types'] = $typeIds;
            $types['types']  = ArrayParameterType::STRING;
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM categories c
               LEFT JOIN categories g ON g.id = c.parent_id
              WHERE c.deleted_at IS NULL AND ' . implode(' AND ', $where) . '
              ORDER BY g.section NULLS FIRST, g."order" NULLS FIRST, c.section NULLS FIRST, c."order" ASC',
            $params,
            $types,
        );

        if ($request->query->getBoolean('tree')) {
            $rows = $this->withChildren($rows);
        }

        return $this->respond($rows, $counts);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function withChildren(array $rows): array
    {
        $ids = array_column($rows, 'id');
        if ($ids === []) {
            return $rows;
        }

        $children = $this->db->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM categories c
              WHERE c.deleted_at IS NULL AND c.active AND c.parent_id::text IN (?)
              ORDER BY c."order" ASC',
            [$ids],
            [ArrayParameterType::STRING],
        );

        $byParent = [];
        foreach ($children as $child) {
            $byParent[$child['parent_id']][] = $child;
        }

        $badges = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT bc.category_id::text AS category_id, b.slug, b.name, b.emoji
               FROM badge_categories bc JOIN badges b ON b.id = bc.badge_id
              WHERE bc.category_id::text IN (?)
              ORDER BY b."order"',
            [$ids],
            [ArrayParameterType::STRING],
        ) as $badge) {
            $category = $badge['category_id'];
            unset($badge['category_id']);
            $badges[$category][] = $badge;
        }

        return array_map(
            static fn (array $row) => $row + [
                'children' => $byParent[$row['id']] ?? [],
                'badges'   => $badges[$row['id']] ?? [],
            ],
            $rows,
        );
    }

    /**
     * Negocios publicados por categoría, contando cada uno en la suya y en
     * todas las de encima: un grupo tiene lo de sus subcategorías más lo que
     * cuelga de él directamente (lo que falta por clasificar).
     *
     * @return array<string,int>
     */
    private function businessCounts(): array
    {
        $rows = $this->db->fetchAllKeyValue(
            'SELECT x.id::text, COUNT(*)
               FROM business b
               JOIN categories c ON c.id = b.category_id
               LEFT JOIN categories p ON p.id = c.parent_id
              CROSS JOIN LATERAL (VALUES (c.id), (p.id), (p.parent_id)) AS x(id)
              WHERE b.deleted_at IS NULL AND b.verified_at IS NOT NULL AND x.id IS NOT NULL
              GROUP BY x.id',
        );

        return array_map('intval', $rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,int>|null $counts con él, fuera lo vacío
     */
    private function respond(array $rows, ?array $counts): Response
    {
        $shape = function (array $row) use ($counts, &$shape): ?array {
            $out = [
                'id'        => $row['id'],
                'slug'      => $row['slug'],
                'name'      => $row['name'],
                'image'     => $row['image'],
                'order'     => $row['order'] === null ? null : (int) $row['order'],
                'mode'      => $row['mode'],
                'parent_id' => $row['parent_id'],
                'section'   => $row['section'],
                'active'    => (bool) $row['active'],
            ];

            if ($counts !== null) {
                $out['business_count'] = $counts[$row['id']] ?? 0;
                if ($out['business_count'] === 0) {
                    return null;
                }
            }

            if (array_key_exists('children', $row)) {
                $children = array_values(array_filter(array_map($shape, $row['children'])));
                // Con recuentos, por volumen: el primer chip siempre tiene
                // contenido (así lo pide el diseño). `usort` es estable, así
                // que a igualdad manda el orden del catálogo.
                if ($counts !== null) {
                    usort($children, static fn (array $a, array $b) => $b['business_count'] <=> $a['business_count']);
                }
                $out['children'] = $children;
                $out['badges']   = $row['badges'] ?? [];
            }

            return $out;
        };

        return new JsonResponse(array_values(array_filter(array_map($shape, $rows))));
    }
}
