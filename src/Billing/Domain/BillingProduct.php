<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Groups subscription plans by business type.
 * Equivalent to Firestore `rates` collection and Stripe Products.
 *
 * The `type` field maps to business categories (e.g. "retail", "restaurant")
 * so the plan selection UI can filter which billing products to show.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_products')]
class BillingProduct
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    /**
     * Stripe Product ID. Used to sync with Stripe and reference prices.
     * Nullable: a billing product can be created locally before being pushed to Stripe.
     */
    #[ORM\Column(name: 'stripe_product_id', type: 'string', length: 255, unique: true, nullable: true)]
    private ?string $stripeProductId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /**
     * Business category types this product targets (e.g. ["retail", "restaurant"]).
     * A product can target multiple categories — empty array means visible to all.
     * Used to filter billing products when a business selects their category.
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $types;

    /**
     * Marketing bullet points shown in the plan selection UI.
     * Stored as jsonb array: ["Point A", "Feature B", ...]
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $description;

    #[ORM\Column(name: 'sort_order', type: 'integer', options: ['default' => 0])]
    private int $sortOrder;

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $isActive;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $name,
        ?string $stripeProductId = null,
        array $types = [],
        ?array $description = null,
        int $sortOrder = 0,
        bool $isActive = true,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id              = $id;
        $this->name            = $name;
        $this->stripeProductId = $stripeProductId;
        $this->types           = $types;
        $this->description     = $description;
        $this->sortOrder       = $sortOrder;
        $this->isActive        = $isActive;
        $this->createdAt       = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt       = new \DateTimeImmutable();
    }

    public function getId(): string                    { return $this->id; }
    public function getStripeProductId(): ?string      { return $this->stripeProductId; }
    public function getName(): string                  { return $this->name; }
    public function getTypes(): array                  { return $this->types; }
    public function getDescription(): ?array           { return $this->description; }
    public function getSortOrder(): int                { return $this->sortOrder; }
    public function isActive(): bool                   { return $this->isActive; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function syncStripeProductId(string $stripeProductId): void
    {
        $this->stripeProductId = $stripeProductId;
        $this->updatedAt       = new \DateTimeImmutable();
    }
}
