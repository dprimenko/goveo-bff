<?php

declare(strict_types=1);

namespace App\Partners\Infrastructure\Repository;

use App\Partners\Domain\Partner;
use App\Partners\Domain\PartnerRepository;
use Doctrine\ORM\EntityManagerInterface;

class DoctrinePartnerRepository implements PartnerRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?Partner
    {
        return $this->em->find(Partner::class, $id);
    }

    public function findAll(): array
    {
        return $this->em->getRepository(Partner::class)->findAll();
    }

    public function save(Partner $partner): void
    {
        $this->em->persist($partner);
        $this->em->flush();
    }

    public function delete(Partner $partner): void
    {
        $this->em->remove($partner);
        $this->em->flush();
    }
}
