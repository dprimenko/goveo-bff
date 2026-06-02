<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Repository;

use App\Business\Domain\BusinessManager;
use App\Business\Domain\BusinessManagerRepository;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineBusinessManagerRepository implements BusinessManagerRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findByUserAndBusiness(string $userId, string $businessId): ?BusinessManager
    {
        return $this->em->find(BusinessManager::class, ['userId' => $userId, 'businessId' => $businessId]);
    }

    public function findByBusinessId(string $businessId): array
    {
        return $this->em->getRepository(BusinessManager::class)->findBy(['businessId' => $businessId]);
    }

    public function findByUserId(string $userId): array
    {
        return $this->em->getRepository(BusinessManager::class)->findBy(['userId' => $userId]);
    }

    public function save(BusinessManager $manager): void
    {
        $this->em->persist($manager);
        $this->em->flush();
    }

    public function delete(BusinessManager $manager): void
    {
        $this->em->remove($manager);
        $this->em->flush();
    }
}
