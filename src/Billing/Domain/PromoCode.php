<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Promotional codes that unlock hidden billing plans.
 *
 * A promo code can:
 *  - Unlock one or more hidden plans for selection.
 *  - Be used to assign a plan to a business without payment (free override).
 */
#[ORM\Entity]
#[ORM\Table(name: 'promo_codes')]
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
     * Open-ended: a code can unlock multiple plans.
     */
    #[ORM\Column(name: 'plan_ids', type: 'json')]
    private array $planIds;

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
        array $planIds,
        ?int $maxUses = null,
        bool $isActive = true,
        ?\DateTimeImmutable $expiresAt = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id        = $id;
        $this->code      = strtoupper(trim($code));
        $this->planIds   = $planIds;
        $this->maxUses   = $maxUses;
        $this->usedCount = 0;
        $this->isActive  = $isActive;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): string                    { return $this->id; }
    public function getCode(): string                  { return $this->code; }
    public function getPlanIds(): array                { return $this->planIds; }
    public function getMaxUses(): ?int                 { return $this->maxUses; }
    public function getUsedCount(): int                { return $this->usedCount; }
    public function isActive(): bool                   { return $this->isActive; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

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
