<?php

declare(strict_types=1);

namespace App\Loyalty\Application;

final class ScanResult
{
    public function __construct(
        public readonly ScanOutcome $outcome,
        /** Nulo sólo si el QR no existe: sin él no se sabe de qué negocio es. */
        public readonly ?string $businessId = null,
        /** Los sellos del usuario después de escanear. */
        public readonly ?int $stamps = null,
        public readonly ?int $rewardStage = null,
        public readonly ?string $rewardLabel = null,
    ) {}
}
