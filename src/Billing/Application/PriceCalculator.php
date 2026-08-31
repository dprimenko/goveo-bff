<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\BillingMode;
use App\Billing\Domain\BillingPlan;
use App\Billing\Domain\Discount;
use App\Billing\Domain\PriceQuote;

/**
 * The single place where a price is worked out.
 *
 * Both the registration API and the Stripe subscription builder read from here,
 * so what the user is shown and what Stripe charges cannot drift apart.
 */
final class PriceCalculator
{
    public function __construct(
        private readonly int $taxPercent,
    ) {}

    public function quote(
        BillingPlan $plan,
        ?Discount $discount = null,
        bool $billedExternally = false,
        ?\DateTimeImmutable $now = null,
    ): PriceQuote {
        $now = $now ?? new \DateTimeImmutable();

        $base = $plan->getAmountCents();

        // A discount on a free plan is a no-op, not an error: codes are reused
        // across tariffs and one of them may well be free.
        $reduction = ($discount !== null && $discount->isActive())
            ? $discount->reductionOn($base)
            : 0;

        $subtotal = $base - $reduction;
        $tax      = (int) round($subtotal * $this->taxPercent / 100);
        $withTax  = $subtotal + $tax;

        // Full price + tax, i.e. what is charged once the discount runs out.
        $recurringTax = (int) round($base * $this->taxPercent / 100);
        $recurring    = $base + $recurringTax;

        // `dueNow` is what the card is charged at sign-up; `firstCharge` is the
        // first real invoice, which for a trial lands later and still carries the
        // discount — showing the full price there would overstate it.
        [$dueNow, $firstCharge, $trialDays, $firstChargeAt] = match ($plan->getMode()) {
            BillingMode::Free          => [0, 0, null, null],
            BillingMode::Paid          => [$withTax, $withTax, null, $now],
            BillingMode::TrialThenPaid => [
                0,
                $withTax,
                $plan->getTrialDays(),
                $now->modify(sprintf('+%d days', (int) $plan->getTrialDays())),
            ],
        };

        // Invoiced outside the app: the price stands, Stripe just never charges it.
        if ($billedExternally) {
            $dueNow    = 0;
            $trialDays = null;
        }

        return new PriceQuote(
            planId:                $plan->getId(),
            planName:              $plan->getName(),
            mode:                  $plan->getMode(),
            currency:              $plan->getCurrency(),
            interval:              $plan->getInterval(),
            intervalCount:         $plan->getIntervalCount(),
            baseCents:             $base,
            discountCents:         $reduction,
            subtotalCents:         $subtotal,
            taxCents:              $tax,
            taxPercent:            $this->taxPercent,
            dueNowCents:           $dueNow,
            firstChargeCents:      $firstCharge,
            recurringCents:        $recurring,
            trialDays:             $trialDays,
            firstChargeAt:         $firstChargeAt,
            requiresPaymentMethod: !$billedExternally && $plan->getMode()->requiresPaymentMethod(),
            collectedInApp:        !$billedExternally,
            discountLabel:         $discount?->getName(),
        );
    }
}
