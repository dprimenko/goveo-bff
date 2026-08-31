<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A code the user types during business registration.
 *
 * A code carries up to three independent effects, and any combination is valid:
 *
 *  - **Plan** (`planIds`)     — unlocks hidden plans. Loading a tariff is the
 *                               common case: every plan is hidden right now.
 *  - **Discount** (`discountId`) — reduces the price of whichever plan is chosen.
 *  - **Attribution** (`partnerId`) — records who brought the business in.
 *
 * The legacy Firestore coupon squashed all of this into one document and used a
 * `noChanges` boolean to signal "this one is not about price". That flag is gone:
 * a code affects price when — and only when — it has a discount attached.
 */
#[ORM\Entity]
#[ORM\Table(name: 'promo_codes')]
#[ORM\Index(name: 'idx_promo_codes_discount', columns: ['discount_id'])]
#[ORM\Index(name: 'idx_promo_codes_partner', columns: ['partner_id'])]
class PromoCode
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $code;

    /**
     * Array of billing_plan UUIDs this code unlocks.
     * Stored as jsonb: ["uuid1", "uuid2", ...]
     * Empty when the code only carries a discount or an attribution.
     */
    #[ORM\Column(name: 'plan_ids', type: 'json', options: ['default' => '[]'])]
    private array $planIds;

    /** Discount applied on top of the selected plan. Null = the code does not touch the price. */
    #[ORM\Column(name: 'discount_id', type: 'guid', nullable: true)]
    private ?string $discountId;

    /** Partner this registration is attributed to. */
    #[ORM\Column(name: 'partner_id', type: 'guid', nullable: true)]
    private ?string $partnerId;

    /**
     * The tariff applies but Goveo does not collect it through Stripe — it is
     * invoiced outside the app. The legacy `noCheckout` flag.
     *
     * This is NOT a discount: the price and what the business owes stay the same,
     * only the collection channel changes. It has to live on the code and not on
     * the plan because the same tariff is sold both ways (`goveo-start-anual24`
     * charges, `goveo-start-anual24ext` does not).
     */
    #[ORM\Column(name: 'billed_externally', type: 'boolean', options: ['default' => false])]
    private bool $billedExternally;

    /** Legacy fields not modelled yet (commission network, import provenance). */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $meta;

    /** Human-readable label shown next to the price ("Promoción Acotex"). */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $label;

    /**
     * Maximum number of times this code can be used. Null = unlimited.
     */
    #[ORM\Column(name: 'max_uses', type: 'integer', nullable: true)]
    private ?int $maxUses;

    #[ORM\Column(name: 'used_count', type: 'integer', options: ['default' => 0])]
    private int $usedCount;

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $isActive;

    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        string $code,
        array $planIds = [],
        ?string $discountId = null,
        ?string $partnerId = null,
        ?string $label = null,
        bool $billedExternally = false,
        ?int $maxUses = null,
        ?array $meta = null,
        bool $isActive = true,
        ?\DateTimeImmutable $expiresAt = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        if ($planIds === [] && $discountId === null && $partnerId === null) {
            throw new \InvalidArgumentException('A promo code must unlock a plan, carry a discount, or attribute a partner.');
        }

        $this->id         = $id;
        $this->code       = strtoupper(trim($code));
        $this->planIds    = array_values($planIds);
        $this->discountId = $discountId;
        $this->partnerId  = $partnerId;
        $this->label      = $label;
        $this->billedExternally = $billedExternally;
        $this->meta       = $meta;
        $this->maxUses    = $maxUses;
        $this->usedCount  = 0;
        $this->isActive   = $isActive;
        $this->expiresAt  = $expiresAt;
        $this->createdAt  = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): string                     { return $this->id; }
    public function getCode(): string                   { return $this->code; }
    public function getPlanIds(): array                 { return $this->planIds; }
    public function getDiscountId(): ?string            { return $this->discountId; }
    public function getPartnerId(): ?string             { return $this->partnerId; }
    public function getLabel(): ?string                 { return $this->label; }
    public function isBilledExternally(): bool          { return $this->billedExternally; }
    public function getMeta(): ?array                   { return $this->meta; }
    public function getMaxUses(): ?int                  { return $this->maxUses; }
    public function getUsedCount(): int                 { return $this->usedCount; }
    public function isActive(): bool                    { return $this->isActive; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function getCreatedAt(): \DateTimeImmutable  { return $this->createdAt; }

    /** Does this code load a tariff, or does it only modify one already chosen? */
    public function unlocksPlans(): bool
    {
        return $this->planIds !== [];
    }

    /** The explicit replacement for the legacy `noChanges` flag, read the right way round. */
    public function hasDiscount(): bool
    {
        return $this->discountId !== null;
    }

    public function isUsable(): bool
    {
        if (!$this->isActive) {
            return false;
        }
        if ($this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable()) {
            return false;
        }
        if ($this->maxUses !== null && $this->usedCount >= $this->maxUses) {
            return false;
        }

        return true;
    }

    public function incrementUsedCount(): void
    {
        ++$this->usedCount;
    }
}
