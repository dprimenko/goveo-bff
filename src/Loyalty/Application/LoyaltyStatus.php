<?php

declare(strict_types=1);

namespace App\Loyalty\Application;

final class LoyaltyStatus
{
    public function __construct(
        public readonly bool $planIncluded,
        public readonly bool $manuallyEnabled,
        public readonly bool $hasRewards,
        /** El negocio la tiene encendida (`activated_at`). */
        public readonly bool $active = false,
    ) {}

    /** Si el negocio tiene derecho a la tarjeta, tenga o no premios puestos. */
    public function isEnabled(): bool
    {
        return $this->planIncluded || $this->manuallyEnabled;
    }

    /**
     * Si puede encenderla: con derecho y al menos un premio. Sin premios no
     * promete nada, y encenderla sería enseñar una tarjeta vacía.
     */
    public function canActivate(): bool
    {
        return $this->isEnabled() && $this->hasRewards;
    }

    /** Si la tarjeta se enseña y se puede usar: además, encendida. */
    public function isAvailable(): bool
    {
        return $this->canActivate() && $this->active;
    }

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return [
            'available'        => $this->isAvailable(),
            'enabled'          => $this->isEnabled(),
            'plan_included'    => $this->planIncluded,
            'manually_enabled' => $this->manuallyEnabled,
            'has_rewards'      => $this->hasRewards,
            'active'           => $this->active,
            'can_activate'     => $this->canActivate(),
        ];
    }
}
