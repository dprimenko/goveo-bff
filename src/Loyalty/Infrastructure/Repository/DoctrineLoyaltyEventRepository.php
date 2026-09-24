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
}
