<?php

declare(strict_types=1);

namespace App\Moderation\Infrastructure\Repository;

use App\Moderation\Domain\BlockTarget;
use App\Moderation\Domain\UserBlock;
use App\Moderation\Domain\UserBlockRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineUserBlockRepository implements UserBlockRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function find(string $userId, BlockTarget $type, string $targetId): ?UserBlock
    {
        return $this->em->getRepository(UserBlock::class)->findOneBy([
            'userId'     => $userId,
            'targetType' => $type,
            'targetId'   => $targetId,
        ]);
    }

    public function findByUser(string $userId): array
    {
        // Con nombre y avatar en la misma query. Un bloqueado que ya no existe
        // (borrado de verdad) sale sin nombre en vez de desaparecer: si no, no
        // habría forma de quitar la fila.
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT ub.target_type, ub.target_id::text AS id,
                    COALESCE(b.name, i.name)     AS name,
                    COALESCE(b.avatar, i.avatar) AS avatar
               FROM user_blocks ub
               LEFT JOIN business    b ON ub.target_type = 'business'   AND b.id = ub.target_id
               LEFT JOIN influencers i ON ub.target_type = 'influencer' AND i.id = ub.target_id
              WHERE ub.user_id = ?
              ORDER BY ub.created_at DESC",
            [$userId],
        );

        $grouped = [
            BlockTarget::Business->value   => [],
            BlockTarget::Influencer->value => [],
        ];

        foreach ($rows as $row) {
            $grouped[$row['target_type']][] = [
                'id'     => $row['id'],
                'name'   => $row['name'],
                'avatar' => $row['avatar'],
            ];
        }

        return $grouped;
    }

    public function save(UserBlock $block): void
    {
        $this->em->persist($block);
        $this->em->flush();
    }

    public function delete(UserBlock $block): void
    {
        $this->em->remove($block);
        $this->em->flush();
    }
}
