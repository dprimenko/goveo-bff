<?php

declare(strict_types=1);

namespace App\Products\Domain;

interface ProductRepository
{
    public function findById(string $id): ?Product;

    public function findBySlug(string $businessId, string $slug): ?Product;

    /** @return Product[] */
    public function findByBusinessId(string $businessId, bool $publishedOnly = true): array;

    /**
     * Paginated products for a business, optionally filtered by subcategory.
     *
     * @return array{items: Product[], total: int}
     */
    public function findByBusinessPaginated(
        string $businessId,
        ?string $subcategoryId,
        int $page,
        int $size,
        bool $publishedOnly = true,
    ): array;

    /** @return Product[] */
    public function findByCategoryId(string $categoryId, bool $publishedOnly = true): array;

    /** @return Product[] */
    public function findBySubcategoryId(string $subcategoryId, bool $publishedOnly = true): array;

    public function save(Product $product): void;

    public function delete(Product $product): void;
}
