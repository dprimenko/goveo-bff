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
