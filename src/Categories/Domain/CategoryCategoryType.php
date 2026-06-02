<?php

declare(strict_types=1);

namespace App\Categories\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'categories_category_types')]
class CategoryCategoryType
{
    #[ORM\Id]
    #[ORM\Column(name: 'category_id', type: 'guid')]
    private string $categoryId;

    #[ORM\Id]
    #[ORM\Column(name: 'type_id', type: 'guid')]
    private string $typeId;

    public function __construct(string $categoryId, string $typeId)
    {
        $this->categoryId = $categoryId;
        $this->typeId = $typeId;
    }

    public function getCategoryId(): string { return $this->categoryId; }
    public function getTypeId(): string { return $this->typeId; }
}
