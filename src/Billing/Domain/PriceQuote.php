<?php

declare(strict_types=1);

namespace App\Billing\Domain;

/**
 * What a business will actually be charged for a plan, resolved server-side.
 *
 * Every figure the registration UI shows comes from here. The legacy flow
 * recomputed the price in three components with three different formulas
 * (BasicData, SelectPlan and RegisterResume in store-register), which is why
 * they disagreed; the app must never do this arithmetic again.
 */
final readonly class PriceQuote
{
    public function __construct(
        public string $planId,
        public string $planName,
        public BillingMode $mode,
        public string $currency,
        public BillingInterval $interval,
        public int $intervalCount,
        /** List price before any discount, excl. tax. */
        public int $baseCents,
        /** Taken off by the discount, excl. tax. */
        public int $discountCents,
        /** baseCents - discountCents, excl. tax. */
        public int $subtotalCents,
        public int $taxCents,
        public int $taxPercent,
        /** What is charged on the first invoice, incl. tax. Zero for free plans and trials. */
        public int $dueNowCents,
        /** Amount of the first real invoice, incl. tax. Zero only for free plans. */
        public int $firstChargeCents,
        /** Recurring charge once discounts and trial are over, incl. tax. */
        public int $recurringCents,
        public ?int $trialDays,
        public ?\DateTimeImmutable $firstChargeAt,
        public bool $requiresPaymentMethod,
        /** False when the tariff is invoiced outside the app (legacy `noCheckout`). */
        public bool $collectedInApp,
        public ?string $discountLabel,
    ) {}

    public function toArray(): array
    {
        return [
            'plan_id'                 => $this->planId,
            'plan_name'               => $this->planName,
            'mode'                    => $this->mode->value,
            'currency'                => $this->currency,
            'interval'                => $this->interval->value,
            'interval_count'          => $this->intervalCount,
            'base_cents'              => $this->baseCents,
            'discount_cents'          => $this->discountCents,
            'subtotal_cents'          => $this->subtotalCents,
            'tax_cents'               => $this->taxCents,
            'tax_percent'             => $this->taxPercent,
            'due_now_cents'           => $this->dueNowCents,
            'first_charge_cents'      => $this->firstChargeCents,
            'recurring_cents'         => $this->recurringCents,
            'trial_days'              => $this->trialDays,
            'first_charge_at'         => $this->firstChargeAt?->format(\DateTimeInterface::ATOM),
            'requires_payment_method' => $this->requiresPaymentMethod,
            'collected_in_app'        => $this->collectedInApp,
            'discount_label'          => $this->discountLabel,
        ];
    }
}
