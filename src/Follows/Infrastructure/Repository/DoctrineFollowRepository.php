<?php

declare(strict_types=1);

namespace App\Follows\Infrastructure\Repository;

use App\Follows\Domain\Follow;
use App\Follows\Domain\FollowRepository;
use App\Follows\Domain\FollowTarget;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineFollowRepository implements FollowRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function find(string $userId, FollowTarget $type, string $targetId): ?Follow
    {
        return $this->em->getRepository(Follow::class)->findOneBy([
            'userId'     => $userId,
            'targetType' => $type,
            'targetId'   => $targetId,
        ]);
    }

    public function findIdsByUser(string $userId): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('f.targetType', 'f.targetId')
            ->from(Follow::class, 'f')
            ->where('f.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getArrayResult();

        $grouped = [
            FollowTarget::Business->value   => [],
            FollowTarget::Influencer->value => [],
        ];

        foreach ($rows as $row) {
            $grouped[$row['targetType']->value][] = $row['targetId'];
        }

        return $grouped;
    }

    public function findByUserWithDetails(string $userId): array
    {
        $db = $this->em->getConnection();

        // Dos consultas y no un LEFT JOIN doble como en los bloqueos: aquí lo
        // borrado no sale (no hay perfil al que llevar), y cada tipo trae sus
        // propias columnas.
        $business = $db->fetchAllAssociative(
            "SELECT b.id::text AS id, b.name, b.avatar, b.slug, b.city
               FROM user_follows uf
               JOIN business b ON b.id = uf.target_id
              WHERE uf.user_id = ? AND uf.target_type = 'business' AND b.deleted_at IS NULL
              ORDER BY uf.created_at DESC",
            [$userId],
        );

        $influencer = $db->fetchAllAssociative(
            "SELECT i.id::text AS id, i.name, i.username, i.avatar
               FROM user_follows uf
               JOIN influencers i ON i.id = uf.target_id
              WHERE uf.user_id = ? AND uf.target_type = 'influencer' AND i.deleted_at IS NULL
              ORDER BY uf.created_at DESC",
            [$userId],
        );

        return [
            FollowTarget::Business->value   => $business,
            FollowTarget::Influencer->value => $influencer,
        ];
    }

    public function countFollowers(FollowTarget $type, string $targetId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(f.id)')
            ->from(Follow::class, 'f')
            ->where('f.targetType = :type')
            ->andWhere('f.targetId = :targetId')
            ->setParameter('type', $type)
            ->setParameter('targetId', $targetId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countFollowersFor(FollowTarget $type, array $targetIds): array
    {
        if ($targetIds === []) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('f.targetId AS targetId', 'COUNT(f.id) AS total')
            ->from(Follow::class, 'f')
            ->where('f.targetType = :type')
            ->andWhere('f.targetId IN (:ids)')
            ->setParameter('type', $type)
            ->setParameter('ids', $targetIds)
            ->groupBy('f.targetId')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['targetId']] = (int) $row['total'];
        }

        return $counts;
    }

    public function save(Follow $follow): void
    {
        $this->em->persist($follow);
        $this->em->flush();
    }

    public function delete(Follow $follow): void
    {
        $this->em->remove($follow);
        $this->em->flush();
    }
}
