<?php

declare(strict_types=1);

namespace App\Business\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'business')]
class Business
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $avatar;

    #[ORM\Column(name: 'main_image', type: 'text', nullable: true)]
    private ?string $mainImage;

    #[ORM\Column(name: 'category_id', type: 'guid')]
    private string $categoryId;

    #[ORM\Column(name: 'creator_id', type: 'guid')]
    private string $creatorId;

    #[ORM\Column(name: 'partner_id', type: 'guid', nullable: true)]
    private ?string $partnerId;

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
        string $slug,
        string $categoryId,
        string $creatorId,
        ?string $name = null,
        ?string $description = null,
        ?string $avatar = null,
        ?string $mainImage = null,
        ?string $partnerId = null,
        ?array $meta = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->id = $id;
        $this->slug = $slug;
        $this->categoryId = $categoryId;
        $this->creatorId = $creatorId;
        $this->name = $name;
        $this->description = $description;
        $this->avatar = $avatar;
        $this->mainImage = $mainImage;
        $this->partnerId = $partnerId;
        $this->meta = $meta;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
        $this->deletedAt = null;
        $this->verifiedAt = null;
    }

    public function getId(): string { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function getName(): ?string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getAvatar(): ?string { return $this->avatar; }
    public function getMainImage(): ?string { return $this->mainImage; }
    public function getCategoryId(): string { return $this->categoryId; }
    public function getCreatorId(): string { return $this->creatorId; }
    public function getPartnerId(): ?string { return $this->partnerId; }
    public function getMeta(): ?array { return $this->meta; }
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

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setAvatar(?string $avatar): self
    {
        $this->avatar = $avatar;
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
