<?php

declare(strict_types=1);

namespace App\Loyalty\Domain;

interface LoyaltyEventRepository
{
    public function save(LoyaltyEvent $event): void;

    /**
     * Cómo se está usando la tarjeta de un negocio, para el panel.
     *
     * @return array{customers: int, stamps: int, redemptions: int, last_activity_at: ?\DateTimeImmutable}
     */
    public function statsForBusiness(string $businessId): array;
}
