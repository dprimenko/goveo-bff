<?php

declare(strict_types=1);

namespace App\Categories\Infrastructure\Repository;

use App\Categories\Domain\CategoryType;
use App\Categories\Domain\CategoryTypeRepository;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineCategoryTypeRepository implements CategoryTypeRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?CategoryType
    {
        return $this->em->find(CategoryType::class, $id);
    }

    public function findAll(): array
    {
        return $this->em->getRepository(CategoryType::class)->findAll();
    }

    public function save(CategoryType $categoryType): void
    {
        $this->em->persist($categoryType);
        $this->em->flush();
    }

    public function delete(CategoryType $categoryType): void
    {
        $this->em->remove($categoryType);
        $this->em->flush();
    }
}
