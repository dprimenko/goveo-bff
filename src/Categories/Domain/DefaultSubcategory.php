<?php

declare(strict_types=1);

namespace App\Categories\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'default_subcategories')]
class DefaultSubcategory
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'category_id', type: 'guid', nullable: true)]
    private ?string $categoryId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $icon;

    #[ORM\Column(name: '`order`', type: 'integer', options: ['default' => 0])]
    private int $order;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt;

    public function __construct(
        string $id,
        string $name,
        ?string $categoryId = null,
        ?string $icon = null,
        int $order = 0,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->categoryId = $categoryId;
        $this->icon = $icon;
        $this->order = $order;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
        $this->deletedAt = null;
    }

    public function getId(): string { return $this->id; }
    public function getCategoryId(): ?string { return $this->categoryId; }
    public function getName(): string { return $this->name; }
    public function getIcon(): ?string { return $this->icon; }
    public function getOrder(): int { return $this->order; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }

    public function softDelete(): self
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
