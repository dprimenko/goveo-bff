<?php

declare(strict_types=1);

namespace App\Business\Domain;

interface BusinessManagerRepository
{
    public function findByUserAndBusiness(string $userId, string $businessId): ?BusinessManager;
    /** @return BusinessManager[] */
    public function findByBusinessId(string $businessId): array;
    /** @return BusinessManager[] */
    public function findByUserId(string $userId): array;
    public function save(BusinessManager $manager): void;
    public function delete(BusinessManager $manager): void;
}
