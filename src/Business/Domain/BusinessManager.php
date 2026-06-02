<?php

declare(strict_types=1);

namespace App\Business\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'business_managers')]
class BusinessManager
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: 'guid')]
    private string $userId;

    #[ORM\Id]
    #[ORM\Column(name: 'business_id', type: 'guid')]
    private string $businessId;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt;

    public function __construct(
        string $userId,
        string $businessId,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->userId = $userId;
        $this->businessId = $businessId;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
        $this->deletedAt = null;
    }

    public function getUserId(): string { return $this->userId; }
    public function getBusinessId(): string { return $this->businessId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }

    public function softDelete(): self
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isDeleted(): bool { return $this->deletedAt !== null; }
}
