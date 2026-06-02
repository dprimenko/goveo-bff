<?php

declare(strict_types=1);

namespace App\Products\Domain;

interface ProductSubcategoryRepository
{
    public function findById(string $id): ?ProductSubcategory;

    /** @return ProductSubcategory[] */
    public function findByBusinessId(string $businessId): array;

    public function save(ProductSubcategory $subcategory): void;

    public function delete(ProductSubcategory $subcategory): void;
}
