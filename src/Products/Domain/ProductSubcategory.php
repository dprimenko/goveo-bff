<?php

declare(strict_types=1);

namespace App\Products\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Subcategories created by businesses to organise their own product catalogue.
 * These are distinct from system-level categories/default_subcategories.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_subcategories')]
class ProductSubcategory
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'business_id', type: 'guid')]
    private string $businessId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $description;

    #[ORM\Column(name: 'sort_order', type: 'integer', options: ['default' => 0])]
    private int $sortOrder;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        string $businessId,
        string $name,
        ?string $description = null,
        int $sortOrder = 0,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id          = $id;
        $this->businessId  = $businessId;
        $this->name        = $name;
        $this->description = $description;
        $this->sortOrder   = $sortOrder;
        $this->createdAt   = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): string                    { return $this->id; }
    public function getBusinessId(): string            { return $this->businessId; }
    public function getName(): string                  { return $this->name; }
    public function getDescription(): ?string          { return $this->description; }
    public function getSortOrder(): int                { return $this->sortOrder; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
