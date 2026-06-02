<?php

declare(strict_types=1);

namespace App\Categories\Domain;

interface DefaultSubcategoryRepository
{
    public function findById(string $id): ?DefaultSubcategory;
    /** @return DefaultSubcategory[] */
    public function findByCategoryId(string $categoryId): array;
    public function save(DefaultSubcategory $subcategory): void;
    public function delete(DefaultSubcategory $subcategory): void;
}
