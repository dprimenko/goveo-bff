<?php

declare(strict_types=1);

namespace App\Loyalty\Infrastructure\Service;

use App\Billing\Domain\BillingPlanRepository;
use App\Billing\Domain\BusinessSubscriptionRepository;
use App\Loyalty\Domain\PlanEligibility;

/**
 * La tarjeta va incluida de PLATINUM hacia arriba: PLATINUM y TOP 3.
 *
 * Se reconoce la tarifa por el prefijo del código del plan (`platinum-anual`,
 * `top3-mensual`…) y no por el nombre del producto, que es un rótulo y se puede
 * cambiar en el panel de Stripe. Una tarifa nueva que deba incluirla se añade
 * aquí.
 *
 * Sólo cuentan las suscripciones activas o en prueba: un impago la quita.
 */
final class BillingPlanEligibility implements PlanEligibility
{
    private const INCLUDED_TIERS = ['platinum', 'top3'];

    public function __construct(
        private readonly BusinessSubscriptionRepository $subscriptions,
        private readonly BillingPlanRepository $plans,
    ) {}

    public function includesLoyalty(string $businessId): bool
    {
        foreach ($this->subscriptions->findByBusinessId($businessId) as $subscription) {
            if (!$subscription->isActive()) {
                continue;
            }

            $code = $this->plans->findById($subscription->getBillingPlanId())?->getCode();
            if ($code !== null && in_array(strtok($code, '-'), self::INCLUDED_TIERS, true)) {
                return true;
            }
        }

        return false;
    }
}
