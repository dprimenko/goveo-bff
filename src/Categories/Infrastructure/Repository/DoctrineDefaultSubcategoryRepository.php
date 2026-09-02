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
        // Sin las dadas de baja y en el orden en que se quieren enseñar: son un
        // menú para elegir, y «Entrantes, Platos, Bebidas, Postres» sólo se lee
        // bien en ese orden.
        return $this->em->getRepository(DefaultSubcategory::class)->findBy(
            ['categoryId' => $categoryId, 'deletedAt' => null],
            ['order' => 'ASC', 'name' => 'ASC'],
        );
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
