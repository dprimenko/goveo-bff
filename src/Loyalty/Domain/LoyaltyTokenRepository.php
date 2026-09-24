<?php

declare(strict_types=1);

namespace App\Loyalty\Domain;

interface LoyaltyTokenRepository
{
    public function findByPlainToken(string $plain): ?LoyaltyToken;

    public function findById(string $id): ?LoyaltyToken;

    public function save(LoyaltyToken $token): void;

    /**
     * Marca el QR como usado **sólo si nadie lo ha usado todavía**, en una única
     * operación contra la base.
     *
     * Leer `isUsed()` y luego guardar no basta: dos móviles que escanean el
     * mismo QR a la vez leerían los dos «sin usar» y sumarían los dos.
     *
     * @return bool false si otro se ha adelantado
     */
    public function claim(LoyaltyToken $token, string $userId): bool;
}
