<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Repository;

use App\Billing\Domain\BillingProduct;
use App\Billing\Domain\BillingProductRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineBillingProductRepository implements BillingProductRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?BillingProduct
    {
        return $this->em->find(BillingProduct::class, $id);
    }

    public function findByStripeProductId(string $stripeProductId): ?BillingProduct
    {
        return $this->em->getRepository(BillingProduct::class)
            ->findOneBy(['stripeProductId' => $stripeProductId]);
    }

    public function findByType(string $type): array
    {
        // Use PostgreSQL jsonb containment operator @> to match products
        // whose `types` array includes the given category type.
        $conn = $this->em->getConnection();
        $sql  = '
            SELECT id FROM billing_products
            WHERE is_active = true
              AND types @> :type
            ORDER BY sort_order ASC
        ';
        $ids = $conn->fetchFirstColumn($sql, ['type' => json_encode([$type])]);

        if (empty($ids)) {
            return [];
        }

        return $this->em->createQueryBuilder()
            ->select('p')
            ->from(BillingProduct::class, 'p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('p.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAllActive(): array
    {
        return $this->em->getRepository(BillingProduct::class)->findBy(
            ['isActive' => true],
            ['sortOrder' => 'ASC'],
        );
    }

    public function save(BillingProduct $product): void
    {
        $this->em->persist($product);
        $this->em->flush();
    }
}
