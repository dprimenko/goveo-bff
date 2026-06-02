<?php

declare(strict_types=1);

namespace App\Billing\Domain;

interface BusinessSubscriptionRepository
{
    public function findById(string $id): ?BusinessSubscription;

    public function findActiveByBusinessId(string $businessId): ?BusinessSubscription;

    public function findByStripeSubscriptionId(string $stripeSubscriptionId): ?BusinessSubscription;

    /** @return BusinessSubscription[] */
    public function findByBusinessId(string $businessId): array;

    public function save(BusinessSubscription $subscription): void;
}
