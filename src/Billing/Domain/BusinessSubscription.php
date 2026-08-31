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
#[ORM\UniqueConstraint(name: 'uniq_subscriptions_payment_link', columns: ['stripe_payment_link_id'])]
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

    /**
     * Enlace de pago de Stripe generado para este negocio. Lleva el id del
     * negocio en `metadata`, que es como el webhook sabe a quién atribuir el
     * cobro. Se conserva para poder reenviárselo a quien no lo haya usado.
     */
    #[ORM\Column(name: 'stripe_payment_link_id', type: 'string', length: 255, nullable: true)]
    private ?string $stripePaymentLinkId = null;

    #[ORM\Column(name: 'payment_url', type: 'text', nullable: true)]
    private ?string $paymentUrl = null;

    /** Cliente de Stripe, que aparece al pagar y no antes. */
    #[ORM\Column(name: 'stripe_customer_id', type: 'string', length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    /**
     * Price realmente cobrado. Puede no ser el del plan: desde el formulario se
     * puede pactar otro importe y entonces se crea un Price a medida.
     */
    #[ORM\Column(name: 'stripe_price_id', type: 'string', length: 255, nullable: true)]
    private ?string $stripePriceId = null;

    #[ORM\Column(name: 'amount_cents', type: 'integer', nullable: true)]
    private ?int $amountCents = null;

    #[ORM\Column(name: 'current_period_start', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $currentPeriodStart;

    #[ORM\Column(name: 'current_period_end', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $currentPeriodEnd;

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
        // Nulos mientras no hay cobro: una suscripción pendiente de pago
        // todavía no tiene periodo, y poner «ahora» sería inventárselo.
        ?\DateTimeImmutable $currentPeriodStart = null,
        ?\DateTimeImmutable $currentPeriodEnd = null,
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

    /**
     * Suscripción contratada desde el formulario web y aún sin pagar. Guarda el
     * enlace y el importe pactado; el resto lo rellena el webhook al cobrar.
     */
    public static function pendingPayment(
        string $id,
        string $businessId,
        string $billingPlanId,
        int $amountCents,
        ?string $stripePriceId,
        ?string $stripePaymentLinkId,
        ?string $paymentUrl,
    ): self {
        $subscription = new self($id, $businessId, $billingPlanId, SubscriptionStatus::PendingPayment);
        $subscription->amountCents          = $amountCents;
        $subscription->stripePriceId        = $stripePriceId;
        $subscription->stripePaymentLinkId  = $stripePaymentLinkId;
        $subscription->paymentUrl           = $paymentUrl;

        return $subscription;
    }

    public function getStripePaymentLinkId(): ?string        { return $this->stripePaymentLinkId; }
    public function getPaymentUrl(): ?string                 { return $this->paymentUrl; }
    public function getStripeCustomerId(): ?string           { return $this->stripeCustomerId; }
    public function getStripePriceId(): ?string              { return $this->stripePriceId; }
    public function getAmountCents(): ?int                   { return $this->amountCents; }

    /** El pago se ha completado: llega de Stripe lo que antes no existía. */
    public function confirmPayment(
        string $stripeSubscriptionId,
        ?string $stripeCustomerId,
        SubscriptionStatus $status,
        ?\DateTimeImmutable $periodStart,
        ?\DateTimeImmutable $periodEnd,
    ): void {
        $this->stripeSubscriptionId = $stripeSubscriptionId;
        $this->stripeCustomerId     = $stripeCustomerId ?? $this->stripeCustomerId;
        $this->status               = $status;
        $this->currentPeriodStart   = $periodStart ?? $this->currentPeriodStart;
        $this->currentPeriodEnd     = $periodEnd ?? $this->currentPeriodEnd;
        $this->updatedAt            = new \DateTimeImmutable();
    }

    public function isPendingPayment(): bool
    {
        return $this->status === SubscriptionStatus::PendingPayment;
    }

    public function getId(): string                          { return $this->id; }
    public function getBusinessId(): string                  { return $this->businessId; }
    public function getBillingPlanId(): string               { return $this->billingPlanId; }
    public function getStripeSubscriptionId(): ?string       { return $this->stripeSubscriptionId; }
    public function getPromoCodeId(): ?string                { return $this->promoCodeId; }
    public function getStatus(): SubscriptionStatus          { return $this->status; }
    public function getCurrentPeriodStart(): ?\DateTimeImmutable { return $this->currentPeriodStart; }
    public function getCurrentPeriodEnd(): ?\DateTimeImmutable   { return $this->currentPeriodEnd; }
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
