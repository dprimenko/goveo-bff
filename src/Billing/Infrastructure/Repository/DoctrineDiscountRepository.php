<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Repository;

use App\Billing\Domain\Discount;
use App\Billing\Domain\DiscountRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineDiscountRepository implements DiscountRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?Discount
    {
        return $this->em->find(Discount::class, $id);
    }

    public function findByStripeCouponId(string $stripeCouponId): ?Discount
    {
        return $this->em->getRepository(Discount::class)
            ->findOneBy(['stripeCouponId' => $stripeCouponId]);
    }

    public function save(Discount $discount): void
    {
        $this->em->persist($discount);
        $this->em->flush();
    }
}
