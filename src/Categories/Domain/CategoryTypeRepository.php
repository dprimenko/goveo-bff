<?php

declare(strict_types=1);

namespace App\Categories\Domain;

interface CategoryTypeRepository
{
    public function findById(string $id): ?CategoryType;
    /** @return CategoryType[] */
    public function findAll(): array;
    public function save(CategoryType $categoryType): void;
    public function delete(CategoryType $categoryType): void;
}
