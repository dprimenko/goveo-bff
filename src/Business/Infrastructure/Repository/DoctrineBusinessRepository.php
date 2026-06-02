<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Repository;

use App\Business\Domain\Business;
use App\Business\Domain\BusinessRepository;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineBusinessRepository implements BusinessRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?Business
    {
        return $this->em->find(Business::class, $id);
    }

    public function findBySlug(string $slug): ?Business
    {
        return $this->em->getRepository(Business::class)->findOneBy(['slug' => $slug]);
    }

    public function findByCreatorId(string $creatorId): array
    {
        return $this->em->getRepository(Business::class)->findBy(['creatorId' => $creatorId]);
    }

    public function save(Business $business): void
    {
        $this->em->persist($business);
        $this->em->flush();
    }

    public function delete(Business $business): void
    {
        $this->em->remove($business);
        $this->em->flush();
    }
}
