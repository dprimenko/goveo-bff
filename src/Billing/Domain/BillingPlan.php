<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * An individual pricing option within a billing product.
 * Equivalent to Firestore `plans` collection and Stripe Prices.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_plans')]
class BillingPlan
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'billing_product_id', type: 'guid')]
    private string $billingProductId;

    /**
     * Stripe Price ID. Nullable until synced with Stripe.
     */
    #[ORM\Column(name: 'stripe_price_id', type: 'string', length: 255, unique: true, nullable: true)]
    private ?string $stripePriceId;

    /**
     * Pre-generated Stripe Payment Link URL for out-of-app payments.
     * Generated via Stripe API when the plan is created/published.
     */
    #[ORM\Column(name: 'stripe_payment_link', type: 'text', nullable: true)]
    private ?string $stripePaymentLink;

    /**
     * Identificador legible para los enlaces de la landing
     * (`?plan=platinum-anual`). Null en las tarifas heredadas, que no se ofrecen.
     */
    #[ORM\Column(type: 'string', length: 60, unique: true, nullable: true)]
    private ?string $code = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /**
     * Price in minor currency units (cents/pence).
     * E.g. 29.90 EUR → 2990. Free plans use 0.
     */
    #[ORM\Column(name: 'amount_cents', type: 'integer')]
    private int $amountCents;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(type: 'string', length: 10, enumType: BillingInterval::class)]
    private BillingInterval $interval;

    /**
     * Number of intervals per billing cycle.
     * 1 = monthly/yearly (standard). 6 = every 6 months (semi-annual).
     */
    #[ORM\Column(name: 'interval_count', type: 'integer', options: ['default' => 1])]
    private int $intervalCount;

    /**
     * How this plan is charged: never, always, or after a free period.
     * Replaces the legacy `noCheckout` / `external` coupon flags — whether money
     * changes hands belongs to the plan, not to the code that unlocks it.
     */
    #[ORM\Column(type: 'string', length: 20, enumType: BillingMode::class, options: ['default' => 'paid'])]
    private BillingMode $mode;

    /**
     * Days free before the first charge. Only meaningful for `trial_then_paid`.
     * Maps straight to Stripe's `trial_period_days`.
     */
    #[ORM\Column(name: 'trial_days', type: 'integer', nullable: true)]
    private ?int $trialDays;

    /**
     * Goveo commission percentage on this plan (e.g. 6 = 6%).
     */
    #[ORM\Column(name: 'commission_percent', type: 'integer', options: ['default' => 0])]
    private int $commissionPercent;

    /**
     * Whether this plan appears in the public plan selection UI.
     * Hidden plans are only accessible via a promo code.
     */
    #[ORM\Column(name: 'is_visible', type: 'boolean', options: ['default' => true])]
    private bool $isVisible;

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $isActive;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $billingProductId,
        string $name,
        int $amountCents,
        string $currency,
        BillingInterval $interval,
        int $intervalCount = 1,
        ?string $code = null,
        BillingMode $mode = BillingMode::Paid,
        ?int $trialDays = null,
        int $commissionPercent = 0,
        bool $isVisible = true,
        bool $isActive = true,
        ?string $stripePriceId = null,
        ?string $stripePaymentLink = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id                = $id;
        $this->billingProductId  = $billingProductId;
        $this->name              = $name;
        $this->amountCents       = $amountCents;
        $this->currency          = strtolower($currency);
        $this->interval          = $interval;
        $this->intervalCount     = $intervalCount;
        $this->code              = $code;
        $this->mode              = $mode;
        $this->trialDays         = $mode === BillingMode::TrialThenPaid ? $trialDays : null;

        if ($mode === BillingMode::TrialThenPaid && ($trialDays === null || $trialDays < 1)) {
            throw new \InvalidArgumentException('A trial_then_paid plan needs trialDays >= 1.');
        }
        if ($mode === BillingMode::Free && $amountCents !== 0) {
            throw new \InvalidArgumentException('A free plan must cost 0.');
        }
        $this->commissionPercent = $commissionPercent;
        $this->isVisible         = $isVisible;
        $this->isActive          = $isActive;
        $this->stripePriceId     = $stripePriceId;
        $this->stripePaymentLink = $stripePaymentLink;
        $this->createdAt         = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt         = new \DateTimeImmutable();
    }

    public function getId(): string                    { return $this->id; }
    public function getBillingProductId(): string      { return $this->billingProductId; }
    public function getStripePriceId(): ?string        { return $this->stripePriceId; }
    public function getStripePaymentLink(): ?string    { return $this->stripePaymentLink; }
    public function getCode(): ?string                 { return $this->code; }
    public function getName(): string                  { return $this->name; }
    public function getAmountCents(): int              { return $this->amountCents; }
    public function getCurrency(): string              { return $this->currency; }
    public function getInterval(): BillingInterval     { return $this->interval; }
    public function getIntervalCount(): int            { return $this->intervalCount; }
    public function getMode(): BillingMode            { return $this->mode; }
    public function getTrialDays(): ?int              { return $this->trialDays; }
    public function getCommissionPercent(): int        { return $this->commissionPercent; }
    public function isVisible(): bool                  { return $this->isVisible; }
    public function isActive(): bool                   { return $this->isActive; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** Price as decimal float for display (e.g. 2990 → 29.90). */
    public function getAmountDecimal(): float
    {
        return $this->amountCents / 100;
    }

    public function syncStripe(string $stripePriceId, ?string $paymentLink = null): void
    {
        $this->stripePriceId     = $stripePriceId;
        $this->stripePaymentLink = $paymentLink ?? $this->stripePaymentLink;
        $this->updatedAt         = new \DateTimeImmutable();
    }

    public function setVisible(bool $visible): void
    {
        $this->isVisible = $visible;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
