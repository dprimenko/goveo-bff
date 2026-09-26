<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use App\Categories\Domain\CategoryRepository;
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
