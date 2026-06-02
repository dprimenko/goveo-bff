<?php

declare(strict_types=1);

namespace App\Categories\Infrastructure\Repository;

use App\Categories\Domain\DefaultSubcategory;
use App\Categories\Domain\DefaultSubcategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineDefaultSubcategoryRepository implements DefaultSubcategoryRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?DefaultSubcategory
    {
        return $this->em->find(DefaultSubcategory::class, $id);
    }

    public function findByCategoryId(string $categoryId): array
    {
        return $this->em->getRepository(DefaultSubcategory::class)->findBy(['categoryId' => $categoryId]);
    }

    public function save(DefaultSubcategory $subcategory): void
    {
        $this->em->persist($subcategory);
        $this->em->flush();
    }

    public function delete(DefaultSubcategory $subcategory): void
    {
        $this->em->remove($subcategory);
        $this->em->flush();
    }
}
