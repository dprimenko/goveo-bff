<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Repository;

use App\Billing\Domain\BillingPlan;
use App\Billing\Domain\BillingPlanRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineBillingPlanRepository implements BillingPlanRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?BillingPlan
    {
        return $this->em->find(BillingPlan::class, $id);
    }

    public function findByStripePriceId(string $stripePriceId): ?BillingPlan
    {
        return $this->em->getRepository(BillingPlan::class)
            ->findOneBy(['stripePriceId' => $stripePriceId]);
    }

    public function findByProductId(string $billingProductId, bool $activeOnly = true): array
    {
        $criteria = ['billingProductId' => $billingProductId];
        if ($activeOnly) {
            $criteria['isActive'] = true;
        }

        return $this->em->getRepository(BillingPlan::class)->findBy(
            $criteria,
            ['amountCents' => 'ASC'],
        );
    }

    public function findVisible(bool $activeOnly = true): array
    {
        $criteria = ['isVisible' => true];
        if ($activeOnly) {
            $criteria['isActive'] = true;
        }

        return $this->em->getRepository(BillingPlan::class)->findBy(
            $criteria,
            ['amountCents' => 'ASC'],
        );
    }

    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->em->createQueryBuilder()
            ->select('p')
            ->from(BillingPlan::class, 'p')
            ->where('p.id IN (:ids)')
            ->andWhere('p.isActive = true')
            ->setParameter('ids', $ids)
            ->orderBy('p.amountCents', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(BillingPlan $plan): void
    {
        $this->em->persist($plan);
        $this->em->flush();
    }
}
