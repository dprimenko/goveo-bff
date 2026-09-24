<?php

declare(strict_types=1);

namespace App\Loyalty\Application;

final class LoyaltyStatus
{
    public function __construct(
        public readonly bool $planIncluded,
        public readonly bool $manuallyEnabled,
        public readonly bool $hasRewards,
    ) {}

    /** Si el negocio tiene derecho a la tarjeta, tenga o no premios puestos. */
    public function isEnabled(): bool
    {
        return $this->planIncluded || $this->manuallyEnabled;
    }

    /** Si la tarjeta se enseña y se puede usar. */
    public function isAvailable(): bool
    {
        return $this->isEnabled() && $this->hasRewards;
    }

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return [
            'available'        => $this->isAvailable(),
            'enabled'          => $this->isEnabled(),
            'plan_included'    => $this->planIncluded,
            'manually_enabled' => $this->manuallyEnabled,
        ];
    }
}
