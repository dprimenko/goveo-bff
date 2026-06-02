<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Active subscription of a business to a billing plan.
 *
 * Two assignment modes:
 *  - Via Stripe payment: stripe_subscription_id is set, promo_code_id is null.
 *  - Via promo code (no payment): promo_code_id is set, stripe_subscription_id is null.
 */
#[ORM\Entity]
#[ORM\Table(name: 'business_subscriptions')]
class BusinessSubscription
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'business_id', type: 'guid')]
    private string $businessId;

    #[ORM\Column(name: 'billing_plan_id', type: 'guid')]
    private string $billingPlanId;

    /**
     * Stripe Subscription ID. Null when the plan was manually assigned via promo.
     */
    #[ORM\Column(name: 'stripe_subscription_id', type: 'string', length: 255, unique: true, nullable: true)]
    private ?string $stripeSubscriptionId;

    /**
     * The promo code used to assign this subscription without payment.
     * Null for paid subscriptions.
     */
    #[ORM\Column(name: 'promo_code_id', type: 'guid', nullable: true)]
    private ?string $promoCodeId;

    #[ORM\Column(type: 'string', length: 20, enumType: SubscriptionStatus::class)]
    private SubscriptionStatus $status;

    #[ORM\Column(name: 'current_period_start', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $currentPeriodStart;

    #[ORM\Column(name: 'current_period_end', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $currentPeriodEnd;

    #[ORM\Column(name: 'cancelled_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $businessId,
        string $billingPlanId,
        SubscriptionStatus $status,
        \DateTimeImmutable $currentPeriodStart,
        \DateTimeImmutable $currentPeriodEnd,
        ?string $stripeSubscriptionId = null,
        ?string $promoCodeId = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id                   = $id;
        $this->businessId           = $businessId;
        $this->billingPlanId        = $billingPlanId;
        $this->status               = $status;
        $this->currentPeriodStart   = $currentPeriodStart;
        $this->currentPeriodEnd     = $currentPeriodEnd;
        $this->stripeSubscriptionId = $stripeSubscriptionId;
        $this->promoCodeId          = $promoCodeId;
        $this->cancelledAt          = null;
        $this->createdAt            = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt            = new \DateTimeImmutable();
    }

    public function getId(): string                          { return $this->id; }
    public function getBusinessId(): string                  { return $this->businessId; }
    public function getBillingPlanId(): string               { return $this->billingPlanId; }
    public function getStripeSubscriptionId(): ?string       { return $this->stripeSubscriptionId; }
    public function getPromoCodeId(): ?string                { return $this->promoCodeId; }
    public function getStatus(): SubscriptionStatus          { return $this->status; }
    public function getCurrentPeriodStart(): \DateTimeImmutable { return $this->currentPeriodStart; }
    public function getCurrentPeriodEnd(): \DateTimeImmutable   { return $this->currentPeriodEnd; }
    public function getCancelledAt(): ?\DateTimeImmutable    { return $this->cancelledAt; }
    public function getCreatedAt(): \DateTimeImmutable       { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable       { return $this->updatedAt; }

    public function isAssignedViaPromo(): bool
    {
        return $this->promoCodeId !== null;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true);
    }

    public function cancel(): void
    {
        $this->status      = SubscriptionStatus::Cancelled;
        $this->cancelledAt = new \DateTimeImmutable();
        $this->updatedAt   = new \DateTimeImmutable();
    }

    public function syncFromStripe(
        SubscriptionStatus $status,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
    ): void {
        $this->status             = $status;
        $this->currentPeriodStart = $periodStart;
        $this->currentPeriodEnd   = $periodEnd;
        $this->updatedAt          = new \DateTimeImmutable();
    }
}
