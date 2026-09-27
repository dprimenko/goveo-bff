<?php

declare(strict_types=1);

namespace App\Influencers\Domain;

interface InfluencerRepository
{
    public function findById(string $id): ?Influencer;
    public function findByUserId(string $userId): ?Influencer;
    public function findByUsername(string $username): ?Influencer;

    /**
     * Busca por nombre o username, sin tildes ni mayúsculas. Con `$blockedBy`
     * (id local de quien mira), fuera los que ha bloqueado.
     *
     * @return array{items: Influencer[], total: int}
     */
    public function searchByName(?string $query, int $page, int $size, ?string $blockedBy = null): array;
    public function save(Influencer $influencer): void;
    public function delete(Influencer $influencer): void;
}
