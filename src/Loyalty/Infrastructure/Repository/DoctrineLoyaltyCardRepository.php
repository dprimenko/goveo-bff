<?php

declare(strict_types=1);

namespace App\Loyalty\Infrastructure\Repository;

use App\Loyalty\Domain\LoyaltyCard;
use App\Loyalty\Domain\LoyaltyCardRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineLoyaltyCardRepository implements LoyaltyCardRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function find(string $userId, string $businessId): ?LoyaltyCard
    {
        return $this->em->getRepository(LoyaltyCard::class)->findOneBy([
            'userId'     => $userId,
            'businessId' => $businessId,
        ]);
    }

    public function findByUser(string $userId): array
    {
        return $this->em->getRepository(LoyaltyCard::class)->findBy(
            ['userId' => $userId],
            ['updatedAt' => 'DESC'],
        );
    }

    public function save(LoyaltyCard $card): void
    {
        $this->em->persist($card);
        $this->em->flush();
    }
}
