<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A price reduction applied on top of a plan. Mirrors a Stripe Coupon.
 *
 * Kept apart from PromoCode on purpose: a code is what the user types (and may
 * unlock plans, attribute a partner, or do nothing to the price), a discount is
 * what money does. The legacy Firestore coupon mixed both in one document and
 * used `noChanges` to tell them apart — here a code simply has no discount.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_discounts')]
#[ORM\UniqueConstraint(name: 'uniq_billing_discounts_stripe', columns: ['stripe_coupon_id'])]
class Discount
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    /** Stripe Coupon ID. Null until synced with Stripe. */
    #[ORM\Column(name: 'stripe_coupon_id', type: 'string', length: 255, unique: true, nullable: true)]
    private ?string $stripeCouponId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /** Percentage off, 1–100. Mutually exclusive with `amountOffCents`. */
    #[ORM\Column(name: 'percent_off', type: 'integer', nullable: true)]
    private ?int $percentOff;

    /** Fixed amount off in minor units. Mutually exclusive with `percentOff`. */
    #[ORM\Column(name: 'amount_off_cents', type: 'integer', nullable: true)]
    private ?int $amountOffCents;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(type: 'string', length: 10, enumType: DiscountDuration::class)]
    private DiscountDuration $duration;

    /** Only meaningful when duration is `repeating`. */
    #[ORM\Column(name: 'duration_in_months', type: 'integer', nullable: true)]
    private ?int $durationInMonths;

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $isActive;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $name,
        DiscountDuration $duration,
        ?int $percentOff = null,
        ?int $amountOffCents = null,
        string $currency = 'eur',
        ?int $durationInMonths = null,
        bool $isActive = true,
        ?string $stripeCouponId = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        if (($percentOff === null) === ($amountOffCents === null)) {
            throw new \InvalidArgumentException('A discount needs exactly one of percentOff or amountOffCents.');
        }
        if ($percentOff !== null && ($percentOff < 1 || $percentOff > 100)) {
            throw new \InvalidArgumentException('percentOff must be between 1 and 100.');
        }
        if ($duration === DiscountDuration::Repeating && $durationInMonths === null) {
            throw new \InvalidArgumentException('A repeating discount needs durationInMonths.');
        }

        $this->id               = $id;
        $this->name             = $name;
        $this->duration         = $duration;
        $this->percentOff       = $percentOff;
        $this->amountOffCents   = $amountOffCents;
        $this->currency         = strtolower($currency);
        $this->durationInMonths = $duration === DiscountDuration::Repeating ? $durationInMonths : null;
        $this->isActive         = $isActive;
        $this->stripeCouponId   = $stripeCouponId;
        $this->createdAt        = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt        = new \DateTimeImmutable();
    }

    public function getId(): string                     { return $this->id; }
    public function getStripeCouponId(): ?string        { return $this->stripeCouponId; }
    public function getName(): string                   { return $this->name; }
    public function getPercentOff(): ?int               { return $this->percentOff; }
    public function getAmountOffCents(): ?int           { return $this->amountOffCents; }
    public function getCurrency(): string               { return $this->currency; }
    public function getDuration(): DiscountDuration     { return $this->duration; }
    public function getDurationInMonths(): ?int         { return $this->durationInMonths; }
    public function isActive(): bool                    { return $this->isActive; }
    public function getCreatedAt(): \DateTimeImmutable  { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable  { return $this->updatedAt; }

    /** How much is taken off `$amountCents`, never more than the amount itself. */
    public function reductionOn(int $amountCents): int
    {
        if ($amountCents <= 0) {
            return 0;
        }

        $reduction = $this->percentOff !== null
            ? (int) round($amountCents * $this->percentOff / 100)
            : (int) $this->amountOffCents;

        return min($reduction, $amountCents);
    }

    public function syncStripe(string $stripeCouponId): void
    {
        $this->stripeCouponId = $stripeCouponId;
        $this->updatedAt      = new \DateTimeImmutable();
    }
}
