<?php

declare(strict_types=1);

namespace App\Influencers\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'influencers')]
class Influencer
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'user_id', type: 'guid')]
    private string $userId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $username;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $avatar;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bio;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $meta;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt;

    #[ORM\Column(name: 'verified_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt;

    public function __construct(
        string $id,
        string $userId,
        string $username,
        string $name,
        ?string $avatar = null,
        ?string $bio = null,
        ?array $meta = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->username = $username;
        $this->name = $name;
        $this->avatar = $avatar;
        $this->bio = $bio;
        $this->meta = $meta;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
        $this->deletedAt = null;
        $this->verifiedAt = null;
    }

    public function getId(): string { return $this->id; }
    public function getUserId(): string { return $this->userId; }
    public function getUsername(): string { return $this->username; }
    public function getName(): string { return $this->name; }
    public function getAvatar(): ?string { return $this->avatar; }
    public function getBio(): ?string { return $this->bio; }
    public function getMeta(): ?array { return $this->meta; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }

    public function setUsername(string $username): self
    {
        $this->username = $username;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setAvatar(?string $avatar): self
    {
        $this->avatar = $avatar;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setBio(?string $bio): self
    {
        $this->bio = $bio;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setMeta(?array $meta): self
    {
        $this->meta = $meta;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function verify(): self
    {
        $this->verifiedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function softDelete(): self
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isDeleted(): bool { return $this->deletedAt !== null; }
    public function isVerified(): bool { return $this->verifiedAt !== null; }
}
