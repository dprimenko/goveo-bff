<?php

declare(strict_types=1);

namespace App\Categories\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'categories')]
class Category
{
    // Which content owners can publish a video under this category.
    public const MODE_INFLUENCER = 'influencer';
    public const MODE_BUSINESS   = 'business';
    public const MODE_BOTH       = 'both';

    /** Slug → mode overrides; everything else defaults to business. */
    private const MODE_BY_SLUG = [
        'historicalbusiness' => self::MODE_BOTH,
        'hostelry'           => self::MODE_BOTH,
        'place'              => self::MODE_INFLUENCER,
        'events'             => self::MODE_INFLUENCER,
        'news'               => self::MODE_INFLUENCER,
        'nature'             => self::MODE_INFLUENCER,
        'culture'            => self::MODE_INFLUENCER,
    ];

    public static function modeForSlug(?string $slug): string
    {
        return self::MODE_BY_SLUG[$slug] ?? self::MODE_BUSINESS;
    }

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $name;

    #[ORM\Column(type: 'string', length: 100, nullable: true, unique: true)]
    private ?string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $image;

    #[ORM\Column(name: '`order`', type: 'integer', nullable: true)]
    private ?int $order;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $partner;

    /** influencer | business | both — who can post video under this category. */
    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::MODE_BUSINESS])]
    private string $mode;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt;

    public function __construct(
        string $id,
        ?string $name = null,
        ?string $slug = null,
        ?string $image = null,
        ?int $order = null,
        ?string $partner = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
        string $mode = self::MODE_BUSINESS,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->slug = $slug;
        $this->image = $image;
        $this->order = $order;
        $this->partner = $partner;
        $this->mode = $mode;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
        $this->deletedAt = null;
    }

    public function getId(): string { return $this->id; }
    public function getName(): ?string { return $this->name; }
    public function getSlug(): ?string { return $this->slug; }
    public function getImage(): ?string { return $this->image; }
    public function getOrder(): ?int { return $this->order; }
    public function getPartner(): ?string { return $this->partner; }
    public function getMode(): string { return $this->mode; }
    public function setMode(string $mode): self { $this->mode = $mode; $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }

    public function setName(?string $name): self
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setSlug(?string $slug): self
    {
        $this->slug = $slug;
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
}
