<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\BillingPlan;
use App\Billing\Domain\BillingPlanRepository;
use App\Billing\Domain\Discount;
use App\Billing\Domain\DiscountRepository;
use App\Billing\Domain\PriceQuote;
use App\Billing\Domain\PromoCode;
use App\Billing\Domain\PromoCodeRepository;

/**
 * Turns a typed code into the tariffs it grants and what each of them costs.
 */
final class PromoCodeResolver
{
    public function __construct(
        private readonly PromoCodeRepository $promoCodes,
        private readonly DiscountRepository $discounts,
        private readonly BillingPlanRepository $plans,
        private readonly PriceCalculator $prices,
    ) {}

    public function resolve(string $rawCode): ResolvedPromoCode
    {
        $code = $this->promoCodes->findByCode($rawCode);

        if ($code === null) {
            return ResolvedPromoCode::invalid('unknown');
        }
        if (!$code->isUsable()) {
            return ResolvedPromoCode::invalid($this->whyUnusable($code));
        }

        $discount = $code->getDiscountId() !== null
            ? $this->discounts->findById($code->getDiscountId())
            : null;

        $plans = $code->unlocksPlans()
            ? array_values(array_filter(
                $this->plans->findByIds($code->getPlanIds()),
                static fn (BillingPlan $p) => $p->isActive(),
            ))
            : [];

        $quotes = array_map(
            fn (BillingPlan $plan) => $this->prices->quote($plan, $discount, $code->isBilledExternally()),
            $plans,
        );

        // A code that unlocks plans but whose plans are all gone is broken, not valid.
        if ($code->unlocksPlans() && $quotes === []) {
            return ResolvedPromoCode::invalid('no_active_plans');
        }

        return ResolvedPromoCode::valid($code, $discount, $quotes);
    }

    private function whyUnusable(PromoCode $code): string
    {
        if (!$code->isActive()) {
            return 'inactive';
        }
        if ($code->getExpiresAt() !== null && $code->getExpiresAt() < new \DateTimeImmutable()) {
            return 'expired';
        }

        return 'exhausted';
    }
}
