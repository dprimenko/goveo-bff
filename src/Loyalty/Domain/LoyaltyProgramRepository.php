<?php

declare(strict_types=1);

namespace App\Loyalty\Domain;

interface LoyaltyProgramRepository
{
    public function findByBusinessId(string $businessId): ?LoyaltyProgram;

    /**
     * @param string[] $businessIds
     *
     * @return array<string, LoyaltyProgram> por id de negocio
     */
    public function findByBusinessIds(array $businessIds): array;

    public function save(LoyaltyProgram $program): void;
}
