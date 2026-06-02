<?php

declare(strict_types=1);

namespace App\Categories\Domain;

interface CategoryRepository
{
    public function findById(string $id): ?Category;
    public function findBySlug(string $slug): ?Category;
    /** @return Category[] */
    public function findAll(): array;
    public function save(Category $category): void;
    public function delete(Category $category): void;
}
