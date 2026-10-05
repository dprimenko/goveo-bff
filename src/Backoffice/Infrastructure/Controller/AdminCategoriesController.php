<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use App\Categories\Domain\CategoryRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * El catálogo de categorías para el panel.
 *
 * - `GET /api/admin/categories`: los grupos por sección, cada uno con **todas**
 *   sus subcategorías —encendidas o no— y cuántos negocios tiene cada una
 *   (publicados y en total). `unclassified` son los colgados del grupo sin
 *   subcategoría. Es lo que hace falta para decidir cuándo encender un grupo:
 *   que ningún chip salga vacío.
 * - `PATCH /api/admin/categories/{id}` `{active?, order?, children_active?}`:
 *   `children_active` enciende o apaga de una vez las subcategorías de un
 *   grupo, que es como se pasa a la fase 2.
 * - `GET /api/admin/categories/events`: los tipos de evento con sus subniveles,
 *   y `PUT /api/admin/categories/order` `{ids}` para ordenarlos (la pestaña
 *   «Eventos» del panel). La web y la app los enseñan en ese orden.
 *
 * No se crean ni se renombran desde aquí: el slug es ruta pública y el nombre
 * una clave de traducción que tiene que existir en la web y en la app, así que
 * una categoría nueva sigue llegando por migración.
 */
#[Route('/api/admin/categories', name: 'admin_categories_')]
class AdminCategoriesController
{
    public function __construct(
        private readonly Connection $db,
        private readonly CategoryRepository $categories,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_BACKOFFICE_ACCESS')]
    public function list(): Response
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT c.id::text AS id, c.slug, c.name, c."order", c.active, c.section,
                    c.parent_id::text AS parent_id,
                    COUNT(b.id) FILTER (WHERE b.verified_at IS NOT NULL) AS published,
                    COUNT(b.id) AS total
               FROM categories c
               LEFT JOIN business b ON b.category_id = c.id AND b.deleted_at IS NULL
              WHERE c.deleted_at IS NULL
                AND (c.section IS NOT NULL
                     OR c.parent_id IN (SELECT id FROM categories WHERE section IS NOT NULL))
              GROUP BY c.id
              ORDER BY c.section, c."order"',
        );

        $groups   = [];
        $children = [];
        foreach ($rows as $row) {
            $item = [
                'id'     => $row['id'],
                'slug'   => $row['slug'],
                'name'   => $row['name'],
                'order'  => $row['order'] === null ? null : (int) $row['order'],
                'active' => (bool) $row['active'],
                'businesses' => ['published' => (int) $row['published'], 'total' => (int) $row['total']],
            ];

            if ($row['section'] !== null) {
                $groups[] = $item + ['section' => $row['section'], 'parent_id' => null];
            } else {
                $children[$row['parent_id']][] = $item;
            }
        }

        return new JsonResponse(array_map(
            static fn (array $group) => [
                ...$group,
                'unclassified' => $group['businesses'],
                'children'     => $children[$group['id']] ?? [],
            ],
            $groups,
        ));
    }

    /**
     * Los tipos de evento con sus subniveles —también los ocultos, que aquí se
     * encienden— y cuántos eventos vigentes tiene cada uno, en el orden en que
     * los enseñan la web y la app. «Otros» va siempre el último y no se mueve.
     */
    #[Route('/events', name: 'events', methods: ['GET'])]
    #[IsGranted('ROLE_BACKOFFICE_ACCESS')]
    public function events(): Response
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT c.id::text AS id, c.slug, c.name, c.\"order\", c.active, c.parent_id::text AS parent_id,
                    (SELECT COUNT(*) FROM geostories g
                      WHERE (g.subcategory_id = c.id OR g.subtype_id = c.id)
                        AND g.deleted_at IS NULL AND (g.ended_at IS NULL OR g.ended_at >= NOW())) AS upcoming
               FROM categories c
              WHERE c.deleted_at IS NULL
                AND (c.parent_id = (SELECT id FROM categories WHERE slug = 'events' AND deleted_at IS NULL)
                     OR c.parent_id IN (SELECT id FROM categories
                                         WHERE parent_id = (SELECT id FROM categories WHERE slug = 'events')))
              ORDER BY c.\"order\", c.slug",
        );

        $item = static fn (array $row) => [
            'id'       => $row['id'],
            'slug'     => $row['slug'],
            'name'     => $row['name'],
            'order'    => $row['order'] === null ? null : (int) $row['order'],
            'active'   => (bool) $row['active'],
            'upcoming' => (int) $row['upcoming'],
        ];

        $types    = [];
        $children = [];
        $typeIds  = [];
        foreach ($rows as $row) {
            $typeIds[$row['id']] = true;
        }
        foreach ($rows as $row) {
            // Un tipo cuelga de `events`; un subnivel, de un tipo.
            if (isset($typeIds[$row['parent_id']])) {
                $children[$row['parent_id']][] = $item($row);
            } else {
                $types[] = $item($row);
            }
        }

        return new JsonResponse(array_map(
            static fn (array $type) => [...$type, 'children' => $children[$type['id']] ?? []],
            $types,
        ));
    }

    /**
     * `PUT /api/admin/categories/order` `{ids: [...]}`: el orden entero de unas
     * hermanas de una vez (los tipos de evento, o los subniveles de uno), de 1 a
     * n. De una vez y no una a una: un orden a medio guardar dejaría dos con el
     * mismo número.
     */
    #[Route('/order', name: 'order', methods: ['PUT'])]
    #[IsGranted('ROLE_CATEGORY_MANAGE')]
    public function order(Request $request): Response
    {
        $ids = (json_decode($request->getContent() ?: '{}', true) ?? [])['ids'] ?? null;
        if (!is_array($ids) || $ids === [] || array_filter($ids, fn ($id) => !is_string($id) || !preg_match('/^[0-9a-f-]{36}$/i', $id))) {
            return new JsonResponse(['error' => 'validation_failed', 'fields' => ['ids' => 'invalid']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Todas hermanas: reordenar mezclando niveles no significa nada.
        $parents = $this->db->fetchFirstColumn(
            'SELECT DISTINCT parent_id::text FROM categories WHERE id::text IN (?) AND deleted_at IS NULL',
            [array_values($ids)],
            [ArrayParameterType::STRING],
        );
        $found = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM categories WHERE id::text IN (?) AND deleted_at IS NULL',
            [array_values($ids)],
            [ArrayParameterType::STRING],
        );
        if (count($parents) !== 1 || $found !== count(array_unique($ids))) {
            return new JsonResponse(['error' => 'validation_failed', 'fields' => ['ids' => 'not_siblings']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->db->transactional(function () use ($ids): void {
            foreach (array_values($ids) as $i => $id) {
                $this->db->executeStatement(
                    'UPDATE categories SET "order" = ?, updated_at = NOW() WHERE id = ?',
                    [$i + 1, $id],
                );
            }
        });

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    #[IsGranted('ROLE_CATEGORY_MANAGE')]
    public function update(string $id, Request $request): Response
    {
        $category = preg_match('/^[0-9a-f-]{36}$/i', $id)
            ? $this->categories->findById($id)
            : $this->categories->findBySlug($id);

        if ($category === null || $category->isDeleted()) {
            return new JsonResponse(['error' => 'Category not found.'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('active', $payload)) {
            $category->setActive((bool) $payload['active']);
        }

        if (array_key_exists('order', $payload)) {
            if (!is_int($payload['order'])) {
                return new JsonResponse(
                    ['error' => 'validation_failed', 'fields' => ['order' => 'invalid']],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            $category->setOrder($payload['order']);
        }

        $this->categories->save($category);

        if (array_key_exists('children_active', $payload)) {
            $this->db->executeStatement(
                'UPDATE categories SET active = ?, updated_at = NOW()
                  WHERE parent_id = ? AND deleted_at IS NULL',
                [(bool) $payload['children_active'], $category->getId()],
                [ParameterType::BOOLEAN],
            );
        }

        return new JsonResponse([
            'id'     => $category->getId(),
            'slug'   => $category->getSlug(),
            'active' => $category->isActive(),
            'order'  => $category->getOrder(),
        ]);
    }
}
