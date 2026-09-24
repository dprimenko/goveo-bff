<?php

declare(strict_types=1);

namespace App\Loyalty\Infrastructure\Repository;

use App\Loyalty\Domain\LoyaltyEvent;
use App\Loyalty\Domain\LoyaltyEventRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineLoyaltyEventRepository implements LoyaltyEventRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function save(LoyaltyEvent $event): void
    {
        $this->em->persist($event);
        $this->em->flush();
    }

    public function statsForBusiness(string $businessId): array
    {
        // Clientes = tarjetas, no usuarios con eventos: quien canjeó y volvió a
        // cero sigue siendo cliente.
        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT
                (SELECT COUNT(*) FROM loyalty_cards WHERE business_id = :id) AS customers,
                COUNT(*) FILTER (WHERE kind = 'stamp')  AS stamps,
                COUNT(*) FILTER (WHERE kind = 'redeem') AS redemptions,
                MAX(created_at) AS last_activity_at
             FROM loyalty_events
             WHERE business_id = :id",
            ['id' => $businessId],
        ) ?: [];

        return [
            'customers'        => (int) ($row['customers'] ?? 0),
            'stamps'           => (int) ($row['stamps'] ?? 0),
            'redemptions'      => (int) ($row['redemptions'] ?? 0),
            'last_activity_at' => isset($row['last_activity_at'])
                ? new \DateTimeImmutable($row['last_activity_at'])
                : null,
        ];
    }
}
