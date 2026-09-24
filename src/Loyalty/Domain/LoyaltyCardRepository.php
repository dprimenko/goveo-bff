<?php

declare(strict_types=1);

namespace App\Loyalty\Domain;

interface LoyaltyCardRepository
{
    public function find(string $userId, string $businessId): ?LoyaltyCard;

    /** @return LoyaltyCard[] las más recientes primero */
    public function findByUser(string $userId): array;

    public function save(LoyaltyCard $card): void;
}
