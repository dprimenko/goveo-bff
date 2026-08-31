<?php

declare(strict_types=1);

namespace App\Billing\Domain;

/**
 * How a plan is charged.
 *
 * Replaces the legacy `noCheckout` / `external` flags of the Firestore coupons:
 * whether money changes hands is a property of the PLAN, never of the code.
 */
enum BillingMode: string
{
    /** Never charged. No Stripe subscription is created. */
    case Free = 'free';

    /** Charged from the first period. */
    case Paid = 'paid';

    /** Free for `trialDays`, then charged. Maps to Stripe's `trial_period_days`. */
    case TrialThenPaid = 'trial_then_paid';

    public function requiresPayment(): bool
    {
        return $this !== self::Free;
    }

    /**
     * Whether the registration flow must collect a card.
     *
     * A trial still needs one up front: without it Stripe cannot charge when the
     * trial ends and the subscription stalls on `missing_payment_method`.
     */
    public function requiresPaymentMethod(): bool
    {
        return $this->requiresPayment();
    }

    /** Whether the first invoice of the subscription is zero. */
    public function isFreeUpFront(): bool
    {
        return $this !== self::Paid;
    }
}
