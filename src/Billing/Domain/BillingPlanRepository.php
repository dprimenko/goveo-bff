<?php

declare(strict_types=1);

namespace App\Billing\Domain;

interface BillingPlanRepository
{
    public function findById(string $id): ?BillingPlan;

    public function findByStripePriceId(string $stripePriceId): ?BillingPlan;

    /** Por el código legible que viaja en los enlaces (`platinum-anual`). */
    public function findByCode(string $code): ?BillingPlan;

    /** @return BillingPlan[] */
    public function findByProductId(string $billingProductId, bool $activeOnly = true): array;

    /** @return BillingPlan[] Visible plans only (for plan selection UI). */
    public function findVisible(bool $activeOnly = true): array;

    /** @return BillingPlan[] All plans matching the given UUIDs (used when a promo code is redeemed). */
    public function findByIds(array $ids): array;

    public function save(BillingPlan $plan): void;
}
