<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Repository;

use App\Billing\Domain\PromoCode;
use App\Billing\Domain\PromoCodeRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrinePromoCodeRepository implements PromoCodeRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?PromoCode
    {
        return $this->em->find(PromoCode::class, $id);
    }

    public function findByCode(string $code): ?PromoCode
    {
        return $this->em->getRepository(PromoCode::class)
            ->findOneBy(['code' => strtoupper(trim($code))]);
    }

    public function save(PromoCode $promoCode): void
    {
        $this->em->persist($promoCode);
        $this->em->flush();
    }
}
