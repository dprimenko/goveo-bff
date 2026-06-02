<?php

declare(strict_types=1);

namespace App\Categories\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'category_types')]
class CategoryType
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt;

    public function __construct(
        string $id,
        string $name,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
        $this->deletedAt = null;
    }

    public function getId(): string { return $this->id; }
    public function getName(): string { return $this->name; }
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
