<?php

declare(strict_types=1);

namespace App\Categories\Infrastructure\Repository;

use App\Categories\Domain\Category;
use App\Categories\Domain\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineCategoryRepository implements CategoryRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?Category
    {
        return $this->em->find(Category::class, $id);
    }

    /**
     * Buscaba por `name`, que es la clave de traducción («category.fashion») y
     * no el slug («fashion»), así que no encontraba nada con lo que se le
     * pasaba. No lo notó nadie porque hasta ahora no lo llamaba nadie.
     */
    public function findBySlug(string $slug): ?Category
    {
        return $this->em->getRepository(Category::class)->findOneBy(['slug' => $slug]);
    }

    public function findAll(): array
    {
        return $this->em->getRepository(Category::class)->findAll();
    }

    public function withDescendants(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        return $this->em->getConnection()->fetchFirstColumn(
            "WITH RECURSIVE tree AS (
                 SELECT id FROM categories WHERE id::text IN ({$placeholders})
                 UNION
                 SELECT c.id FROM categories c JOIN tree t ON c.parent_id = t.id
                  WHERE c.deleted_at IS NULL
             )
             SELECT id::text FROM tree",
            array_values($ids),
        );
    }

    public function tourismIds(): array
    {
        $placeholders = implode(', ', array_fill(0, count(Category::LEGACY_TOURISM_SLUGS), '?'));

        $roots = $this->em->getConnection()->fetchFirstColumn(
            "SELECT id::text FROM categories
              WHERE deleted_at IS NULL
                AND (section = ? OR partner IS NOT NULL OR slug IN ({$placeholders}))",
            [Category::SECTION_TOURISM, ...Category::LEGACY_TOURISM_SLUGS],
        );

        return $this->withDescendants($roots);
    }

    public function isAssignableToBusiness(string $id): bool
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            return false;
        }

        return (bool) $this->em->getConnection()->fetchOne(
            "SELECT 1 FROM categories c
               LEFT JOIN categories p ON p.id = c.parent_id
              WHERE c.id = ? AND c.deleted_at IS NULL AND c.mode <> ?
                AND (c.parent_id IS NULL OR p.section IS NOT NULL)",
            [$id, Category::MODE_INFLUENCER],
        );
    }

    public function save(Category $category): void
    {
        $this->em->persist($category);
        $this->em->flush();
    }

    public function delete(Category $category): void
    {
        $this->em->remove($category);
        $this->em->flush();
    }
}
