<?php

declare(strict_types=1);

namespace App\Billing\Domain;

interface DiscountRepository
{
    public function findById(string $id): ?Discount;

    public function findByStripeCouponId(string $stripeCouponId): ?Discount;

    public function save(Discount $discount): void;
}
