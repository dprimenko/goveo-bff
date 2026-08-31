<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Repository;

use App\Billing\Domain\BusinessSubscription;
use App\Billing\Domain\BusinessSubscriptionRepository;
use App\Billing\Domain\SubscriptionStatus;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineBusinessSubscriptionRepository implements BusinessSubscriptionRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?BusinessSubscription
    {
        return $this->em->find(BusinessSubscription::class, $id);
    }

    public function findActiveByBusinessId(string $businessId): ?BusinessSubscription
    {
        return $this->em->createQueryBuilder()
            ->select('s')
            ->from(BusinessSubscription::class, 's')
            ->where('s.businessId = :businessId')
            ->andWhere('s.status IN (:activeStatuses)')
            ->setParameter('businessId', $businessId)
            ->setParameter('activeStatuses', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Trialing->value,
            ])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByStripeSubscriptionId(string $stripeSubscriptionId): ?BusinessSubscription
    {
        return $this->em->getRepository(BusinessSubscription::class)
            ->findOneBy(['stripeSubscriptionId' => $stripeSubscriptionId]);
    }

    public function findByStripePaymentLinkId(string $stripePaymentLinkId): ?BusinessSubscription
    {
        return $this->em->getRepository(BusinessSubscription::class)
            ->findOneBy(['stripePaymentLinkId' => $stripePaymentLinkId]);
    }

    public function findByBusinessId(string $businessId): array
    {
        return $this->em->getRepository(BusinessSubscription::class)->findBy(
            ['businessId' => $businessId],
            ['createdAt' => 'DESC'],
        );
    }

    public function save(BusinessSubscription $subscription): void
    {
        $this->em->persist($subscription);
        $this->em->flush();
    }
}
