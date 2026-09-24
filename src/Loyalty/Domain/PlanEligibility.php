<?php

declare(strict_types=1);

namespace App\Loyalty\Domain;

/**
 * Si la tarifa del negocio incluye la tarjeta de fidelización.
 *
 * Es una interfaz para que las reglas de la tarjeta no dependan de cómo está
 * montada la facturación, y se puedan probar sin ella.
 */
interface PlanEligibility
{
    public function includesLoyalty(string $businessId): bool;
}
