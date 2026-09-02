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

    public function findByBusinessPaginated(
        string $businessId,
        ?string $subcategoryId,
        int $page,
        int $size,
        bool $publishedOnly = true,
    ): array {
        $base = function () use ($businessId, $subcategoryId, $publishedOnly) {
            $qb = $this->em->createQueryBuilder()
                ->from(Product::class, 'p')
                ->where('p.businessId = :businessId')
                ->andWhere('p.deletedAt IS NULL')
                ->setParameter('businessId', $businessId);

            if ($subcategoryId !== null) {
                $qb->andWhere('p.subcategoryId = :subcategoryId')
                    ->setParameter('subcategoryId', $subcategoryId);
            }

            if ($publishedOnly) {
                $qb->andWhere('p.publishedAt IS NOT NULL');
            }

            return $qb;
        };

        $total = (int) $base()
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $base()
            ->select('p')
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult(max(0, $page) * $size)
            ->setMaxResults($size)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
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

    public function clearSubcategory(string $subcategoryId): int
    {
        return (int) $this->em->createQuery(
            'UPDATE ' . Product::class . ' p
                SET p.subcategoryId = NULL
              WHERE p.subcategoryId = :subcategory'
        )->setParameter('subcategory', $subcategoryId)->execute();
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
