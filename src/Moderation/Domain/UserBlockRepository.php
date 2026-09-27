<?php

declare(strict_types=1);

namespace App\Moderation\Domain;

interface UserBlockRepository
{
    public function find(string $userId, BlockTarget $type, string $targetId): ?UserBlock;

    /**
     * Los que ha bloqueado el usuario, agrupados por tipo, con nombre y avatar:
     * es lo que pinta la lista de «Usuarios bloqueados», y los ids solos no le
     * dicen a nadie a quién está desbloqueando.
     *
     * @return array{business: array<array{id: string, name: ?string, avatar: ?string}>, influencer: array<array{id: string, name: ?string, avatar: ?string}>}
     */
    public function findByUser(string $userId): array;

    public function save(UserBlock $block): void;

    public function delete(UserBlock $block): void;
}
