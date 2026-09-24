<?php

declare(strict_types=1);

namespace App\Loyalty\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Historial de la tarjeta: cada sello y cada canje.
 *
 * La tarjeta sólo guarda cuántos sellos lleva, y al canjear vuelve a cero; sin
 * esto no quedaría rastro de qué premios se han dado. El premio se copia tal
 * cual estaba al canjearlo, porque el negocio puede cambiarlo después.
 */
#[ORM\Entity]
#[ORM\Table(name: 'loyalty_events')]
#[ORM\Index(name: 'idx_loyalty_events_business', columns: ['business_id', 'created_at'])]
#[ORM\Index(name: 'idx_loyalty_events_card', columns: ['card_id'])]
class LoyaltyEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'card_id', type: 'guid')]
    private string $cardId;

    #[ORM\Column(name: 'user_id', type: 'guid')]
    private string $userId;

    #[ORM\Column(name: 'business_id', type: 'guid')]
    private string $businessId;

    #[ORM\Column(type: 'string', length: 10, enumType: LoyaltyTokenKind::class)]
    private LoyaltyTokenKind $kind;

    #[ORM\Column(name: 'token_id', type: 'guid')]
    private string $tokenId;

    #[ORM\Column(name: 'reward_stage', type: 'smallint', nullable: true)]
    private ?int $rewardStage;

    #[ORM\Column(name: 'reward_label', type: 'string', length: 80, nullable: true)]
    private ?string $rewardLabel;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        LoyaltyCard $card,
        LoyaltyToken $token,
        ?string $rewardLabel = null,
    ) {
        $this->id          = $id;
        $this->cardId      = $card->getId();
        $this->userId      = $card->getUserId();
        $this->businessId  = $card->getBusinessId();
        $this->kind        = $token->getKind();
        $this->tokenId     = $token->getId();
        $this->rewardStage = $token->getRewardStage();
        $this->rewardLabel = $rewardLabel;
        $this->createdAt   = new \DateTimeImmutable();
    }

    public function getKind(): LoyaltyTokenKind { return $this->kind; }
    public function getRewardStage(): ?int      { return $this->rewardStage; }
    public function getRewardLabel(): ?string   { return $this->rewardLabel; }
}
