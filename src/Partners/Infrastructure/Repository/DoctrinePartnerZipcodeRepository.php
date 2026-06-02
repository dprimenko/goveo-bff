<?php

declare(strict_types=1);

namespace App\Partners\Infrastructure\Repository;

use App\Partners\Domain\PartnerZipcode;
use App\Partners\Domain\PartnerZipcodeRepository;
use Doctrine\ORM\EntityManagerInterface;

class DoctrinePartnerZipcodeRepository implements PartnerZipcodeRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?PartnerZipcode
    {
        return $this->em->find(PartnerZipcode::class, $id);
    }

    public function findByPartnerId(string $partnerId): array
    {
        return $this->em->getRepository(PartnerZipcode::class)->findBy(['partnerId' => $partnerId]);
    }

    public function save(PartnerZipcode $zipcode): void
    {
        $this->em->persist($zipcode);
        $this->em->flush();
    }

    public function delete(PartnerZipcode $zipcode): void
    {
        $this->em->remove($zipcode);
        $this->em->flush();
    }
}
