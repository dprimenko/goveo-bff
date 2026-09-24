<?php

declare(strict_types=1);

namespace App\Loyalty\Domain;

interface LoyaltyEventRepository
{
    public function save(LoyaltyEvent $event): void;
}
