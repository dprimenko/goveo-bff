<?php

declare(strict_types=1);

namespace App\Influencers\Infrastructure\Repository;

use App\Influencers\Domain\Influencer;
use App\Influencers\Domain\InfluencerRepository;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineInfluencerRepository implements InfluencerRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?Influencer
    {
        return $this->em->find(Influencer::class, $id);
    }

    public function findByUserId(string $userId): ?Influencer
    {
        return $this->em->getRepository(Influencer::class)->findOneBy(['userId' => $userId]);
    }

    public function findByUsername(string $username): ?Influencer
    {
        return $this->em->getRepository(Influencer::class)->findOneBy(['username' => $username]);
    }

    public function save(Influencer $influencer): void
    {
        $this->em->persist($influencer);
        $this->em->flush();
    }

    public function delete(Influencer $influencer): void
    {
        $this->em->remove($influencer);
        $this->em->flush();
    }
}
