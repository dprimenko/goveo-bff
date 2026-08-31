<?php

declare(strict_types=1);

namespace App\Follows\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Un usuario sigue a un negocio o a un influencer.
 *
 * `user_id` es el id **local** (`users.id`), no el `sub` del JWT de Keycloak:
 * el puente lo hace `LocalUserResolver`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_follows')]
#[ORM\UniqueConstraint(name: 'uniq_user_follows', columns: ['user_id', 'target_type', 'target_id'])]
#[ORM\Index(name: 'idx_user_follows_target', columns: ['target_type', 'target_id'])]
#[ORM\Index(name: 'idx_user_follows_user', columns: ['user_id'])]
class Follow
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'user_id', type: 'guid')]
    private string $userId;

    #[ORM\Column(name: 'target_type', type: 'string', length: 20, enumType: FollowTarget::class)]
    private FollowTarget $targetType;

    #[ORM\Column(name: 'target_id', type: 'guid')]
    private string $targetId;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        string $userId,
        FollowTarget $targetType,
        string $targetId,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id         = $id;
        $this->userId     = $userId;
        $this->targetType = $targetType;
        $this->targetId   = $targetId;
        $this->createdAt  = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): string                    { return $this->id; }
    public function getUserId(): string                { return $this->userId; }
    public function getTargetType(): FollowTarget      { return $this->targetType; }
    public function getTargetId(): string              { return $this->targetId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
