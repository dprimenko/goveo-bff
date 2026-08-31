<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Repository;

use App\GeoStories\Domain\GeoStoryLike;
use App\GeoStories\Domain\GeoStoryLikeRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineGeoStoryLikeRepository implements GeoStoryLikeRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function find(string $userId, string $geoStoryId): ?GeoStoryLike
    {
        return $this->em->getRepository(GeoStoryLike::class)->findOneBy([
            'userId'     => $userId,
            'geoStoryId' => $geoStoryId,
        ]);
    }

    public function findIdsByUser(string $userId): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('l.geoStoryId')
            ->from(GeoStoryLike::class, 'l')
            ->where('l.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'geoStoryId');
    }

    public function countFor(string $geoStoryId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(GeoStoryLike::class, 'l')
            ->where('l.geoStoryId = :id')
            ->setParameter('id', $geoStoryId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(GeoStoryLike $like): void
    {
        $this->em->persist($like);
        $this->em->flush();
    }

    public function delete(GeoStoryLike $like): void
    {
        $this->em->remove($like);
        $this->em->flush();
    }
}
