<?php

declare(strict_types=1);

namespace App\Loyalty\Application;

use App\Loyalty\Domain\LoyaltyProgram;
use App\Loyalty\Domain\PlanEligibility;

/**
 * Si un negocio ofrece tarjeta ahora mismo, y por qué.
 *
 * La tiene quien la incluye en su tarifa (PLATINUM o más) o quien la tiene
 * activada a mano desde el panel, **y además** ha puesto al menos un premio:
 * una tarjeta sin premios no promete nada, y enseñarla sólo confundiría.
 */
final class LoyaltyAvailability
{
    public function __construct(
        private readonly PlanEligibility $plans,
    ) {}

    public function check(string $businessId, ?LoyaltyProgram $program): LoyaltyStatus
    {
        return new LoyaltyStatus(
            planIncluded: $this->plans->includesLoyalty($businessId),
            manuallyEnabled: $program?->isManuallyEnabled() ?? false,
            hasRewards: $program?->hasRewards() ?? false,
        );
    }
}
