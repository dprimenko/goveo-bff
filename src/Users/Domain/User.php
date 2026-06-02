<?php

declare(strict_types=1);

namespace App\Users\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $email;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $name;

    #[ORM\Column(name: 'profile_image', type: 'text', nullable: true)]
    private ?string $profileImage;

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
        ?string $email = null,
        ?string $name = null,
        ?string $profileImage = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->name = $name;
        $this->profileImage = $profileImage;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
        $this->deletedAt = null;
        $this->verifiedAt = null;
    }

    public function getId(): string { return $this->id; }
    public function getEmail(): ?string { return $this->email; }
    public function getName(): ?string { return $this->name; }
    public function getProfileImage(): ?string { return $this->profileImage; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }

    public function setName(?string $name): self
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setProfileImage(?string $profileImage): self
    {
        $this->profileImage = $profileImage;
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
