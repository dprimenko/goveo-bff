<?php

declare(strict_types=1);

namespace App\Billing\Domain;

interface PromoCodeRepository
{
    public function findById(string $id): ?PromoCode;

    public function findByCode(string $code): ?PromoCode;

    public function save(PromoCode $promoCode): void;
}
