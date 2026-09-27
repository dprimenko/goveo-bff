<?php

declare(strict_types=1);

namespace App\Moderation\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Un usuario no quiere volver a ver a un negocio o influencer. Misma forma que
 * `user_follows`, y `user_id` es igual el id local.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_blocks')]
#[ORM\UniqueConstraint(name: 'uniq_user_blocks', columns: ['user_id', 'target_type', 'target_id'])]
#[ORM\Index(name: 'idx_user_blocks_user', columns: ['user_id'])]
class UserBlock
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'user_id', type: 'guid')]
    private string $userId;

    #[ORM\Column(name: 'target_type', type: 'string', length: 20, enumType: BlockTarget::class)]
    private BlockTarget $targetType;

    #[ORM\Column(name: 'target_id', type: 'guid')]
    private string $targetId;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        string $userId,
        BlockTarget $targetType,
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
    public function getTargetType(): BlockTarget       { return $this->targetType; }
    public function getTargetId(): string              { return $this->targetId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
