<?php

declare(strict_types=1);

namespace App\Business\Domain;

interface BusinessRepository
{
    public function findById(string $id): ?Business;
    public function findBySlug(string $slug): ?Business;

    /** @return Business[] */
    public function findByCreatorId(string $creatorId): array;

    public function save(Business $business): void;
    public function delete(Business $business): void;
}
