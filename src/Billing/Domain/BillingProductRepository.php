<?php

declare(strict_types=1);

namespace App\Billing\Domain;

interface BillingProductRepository
{
    public function findById(string $id): ?BillingProduct;

    public function findByStripeProductId(string $stripeProductId): ?BillingProduct;

    /** @return BillingProduct[] Products whose `types` array contains the given category type. */
    public function findByType(string $type): array;

    /** @return BillingProduct[] */
    public function findAllActive(): array;

    public function save(BillingProduct $product): void;
}
