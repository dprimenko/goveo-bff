<?php

declare(strict_types=1);

namespace App\Loyalty\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Los sellos que lleva un usuario en un negocio.
 *
 * Se crea con el primer sello: hasta entonces la tarjeta existe sólo en la app,
 * vacía, y no hace falta guardar nada.
 *
 * Canjear **cualquier** premio la deja a cero, también el del sello 3: el
 * cliente elige entre un premio pequeño ya o esperar al grande.
 */
#[ORM\Entity]
#[ORM\Table(name: 'loyalty_cards')]
#[ORM\UniqueConstraint(name: 'uniq_loyalty_card', columns: ['user_id', 'business_id'])]
#[ORM\Index(name: 'idx_loyalty_cards_business', columns: ['business_id'])]
class LoyaltyCard
{
    public const MAX_STAMPS = 5;

    /** Los sellos que dan premio. */
    public const REWARD_STAGES = [3, 5];

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'user_id', type: 'guid')]
    private string $userId;

    #[ORM\Column(name: 'business_id', type: 'guid')]
    private string $businessId;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $stamps;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $userId, string $businessId)
    {
        $this->id         = $id;
        $this->userId     = $userId;
        $this->businessId = $businessId;
        $this->stamps     = 0;
        $this->createdAt  = new \DateTimeImmutable();
        $this->updatedAt  = $this->createdAt;
    }

    public function getId(): string                    { return $this->id; }
    public function getUserId(): string                { return $this->userId; }
    public function getBusinessId(): string            { return $this->businessId; }
    public function getStamps(): int                   { return $this->stamps; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function isFull(): bool
    {
        return $this->stamps >= self::MAX_STAMPS;
    }

    /** @return bool false si ya estaba llena: el sello no cuenta */
    public function addStamp(): bool
    {
        if ($this->isFull()) {
            return false;
        }

        $this->stamps++;
        $this->updatedAt = new \DateTimeImmutable();

        return true;
    }

    public function canRedeem(int $stage): bool
    {
        return in_array($stage, self::REWARD_STAGES, true) && $this->stamps >= $stage;
    }

    /** @return bool false si no llega a ese premio */
    public function redeem(int $stage): bool
    {
        if (!$this->canRedeem($stage)) {
            return false;
        }

        $this->stamps    = 0;
        $this->updatedAt = new \DateTimeImmutable();

        return true;
    }
}
