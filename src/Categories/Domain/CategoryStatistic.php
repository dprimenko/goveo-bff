<?php

declare(strict_types=1);

namespace App\Categories\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'categories_statistics')]
class CategoryStatistic
{
    #[ORM\Id]
    #[ORM\Column(name: 'category_id', type: 'guid')]
    private string $categoryId;

    #[ORM\Column(name: 'business_counter', type: 'integer', options: ['default' => 0])]
    private int $businessCounter;

    #[ORM\Column(name: 'geostories_counter', type: 'integer', options: ['default' => 0])]
    private int $geostoriesCounter;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $categoryId,
        int $businessCounter = 0,
        int $geostoriesCounter = 0,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->categoryId = $categoryId;
        $this->businessCounter = $businessCounter;
        $this->geostoriesCounter = $geostoriesCounter;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
    }

    public function getCategoryId(): string { return $this->categoryId; }
    public function getBusinessCounter(): int { return $this->businessCounter; }
    public function getGeostoriesCounter(): int { return $this->geostoriesCounter; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function incrementBusinessCounter(): self
    {
        ++$this->businessCounter;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function incrementGeostoriesCounter(): self
    {
        ++$this->geostoriesCounter;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
