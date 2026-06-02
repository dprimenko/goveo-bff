<?php

declare(strict_types=1);

namespace App\Products\Infrastructure\Repository;

use App\Products\Domain\ProductSubcategory;
use App\Products\Domain\ProductSubcategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineProductSubcategoryRepository implements ProductSubcategoryRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?ProductSubcategory
    {
        return $this->em->find(ProductSubcategory::class, $id);
    }

    public function findByBusinessId(string $businessId): array
    {
        return $this->em->getRepository(ProductSubcategory::class)->findBy(
            ['businessId' => $businessId],
            ['sortOrder' => 'ASC', 'name' => 'ASC'],
        );
    }

    public function save(ProductSubcategory $subcategory): void
    {
        $this->em->persist($subcategory);
        $this->em->flush();
    }

    public function delete(ProductSubcategory $subcategory): void
    {
        $this->em->remove($subcategory);
        $this->em->flush();
    }
}
