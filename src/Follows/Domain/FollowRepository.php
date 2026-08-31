<?php

declare(strict_types=1);

namespace App\Follows\Domain;

interface FollowRepository
{
    public function find(string $userId, FollowTarget $type, string $targetId): ?Follow;

    /**
     * Ids seguidos por el usuario, agrupados por tipo.
     *
     * @return array{business: string[], influencer: string[]}
     */
    public function findIdsByUser(string $userId): array;

    /** Nº de seguidores reales de un destino. */
    public function countFollowers(FollowTarget $type, string $targetId): int;

    /**
     * Nº de seguidores de varios destinos del mismo tipo, en una sola query.
     *
     * @param  string[] $targetIds
     * @return array<string, int> targetId => nº de seguidores
     */
    public function countFollowersFor(FollowTarget $type, array $targetIds): array;

    public function save(Follow $follow): void;

    public function delete(Follow $follow): void;
}
