<?php

declare(strict_types=1);

namespace App\Products\Infrastructure\Repository;

use App\Products\Domain\Product;
use App\Products\Domain\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineProductRepository implements ProductRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?Product
    {
        return $this->em->find(Product::class, $id);
    }

    public function findBySlug(string $businessId, string $slug): ?Product
    {
        return $this->em->getRepository(Product::class)->findOneBy([
            'businessId' => $businessId,
            'slug'       => $slug,
            'deletedAt'  => null,
        ]);
    }

    public function findByBusinessId(string $businessId, bool $publishedOnly = true): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.businessId = :businessId')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('businessId', $businessId)
            ->orderBy('p.createdAt', 'DESC');

        if ($publishedOnly) {
            $qb->andWhere('p.publishedAt IS NOT NULL');
        }

        return $qb->getQuery()->getResult();
    }

    public function findByCategoryId(string $categoryId, bool $publishedOnly = true): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.categoryId = :categoryId')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('categoryId', $categoryId)
            ->orderBy('p.createdAt', 'DESC');

        if ($publishedOnly) {
            $qb->andWhere('p.publishedAt IS NOT NULL');
        }

        return $qb->getQuery()->getResult();
    }

    public function findBySubcategoryId(string $subcategoryId, bool $publishedOnly = true): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.subcategoryId = :subcategoryId')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('subcategoryId', $subcategoryId)
            ->orderBy('p.createdAt', 'DESC');

        if ($publishedOnly) {
            $qb->andWhere('p.publishedAt IS NOT NULL');
        }

        return $qb->getQuery()->getResult();
    }

    public function save(Product $product): void
    {
        $this->em->persist($product);
        $this->em->flush();
    }

    public function delete(Product $product): void
    {
        $this->em->remove($product);
        $this->em->flush();
    }
}
