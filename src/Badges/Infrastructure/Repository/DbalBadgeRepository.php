<?php

declare(strict_types=1);

namespace App\Badges\Infrastructure\Repository;

use App\Badges\Domain\BadgeRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

class DbalBadgeRepository implements BadgeRepository
{
    private const COLUMNS = 'b.id::text AS id, b.slug, b.name, b.emoji';

    public function __construct(
        private readonly Connection $db,
    ) {}

    public function all(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM badges b ORDER BY b."order", b.slug',
        );
    }

    public function forBusinesses(array $businessIds): array
    {
        if ($businessIds === []) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT bb.business_id::text AS business_id, ' . self::COLUMNS . '
               FROM business_badges bb JOIN badges b ON b.id = bb.badge_id
              WHERE bb.business_id::text IN (?)
              ORDER BY b."order", b.slug',
            [array_values($businessIds)],
            [ArrayParameterType::STRING],
        );

        $byBusiness = [];
        foreach ($rows as $row) {
            $businessId = $row['business_id'];
            unset($row['business_id']);
            $byBusiness[$businessId][] = $row;
        }

        return $byBusiness;
    }

    public function replaceForBusiness(string $businessId, array $badges): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn ($b) => is_string($b) ? $this->resolve($b) : null,
            $badges,
        ))));

        $this->db->transactional(function (Connection $db) use ($businessId, $ids): void {
            $db->executeStatement('DELETE FROM business_badges WHERE business_id = ?', [$businessId]);
            foreach ($ids as $id) {
                $db->executeStatement(
                    'INSERT INTO business_badges (business_id, badge_id) VALUES (?, ?)',
                    [$businessId, $id],
                );
            }
        });

        return $this->forBusinesses([$businessId])[$businessId] ?? [];
    }

    public function resolve(string $slugOrId): ?string
    {
        $id = $this->db->fetchOne(
            'SELECT id::text FROM badges WHERE slug = ? OR id::text = ?',
            [$slugOrId, $slugOrId],
        );

        return $id === false ? null : $id;
    }
}
