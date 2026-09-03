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
    /**
     * @param bool $withImageOnly deja fuera los productos sin foto. La ficha
     *                            pública es un escaparate y una tarjeta con el
     *                            hueco gris se lee como algo roto; a quien
     *                            gestiona la tienda sí se le enseñan, que para
     *                            eso tiene que poder ponerles la foto.
     */
    public function findByBusinessPaginated(
        string $businessId,
        ?string $subcategoryId,
        int $page,
        int $size,
        bool $publishedOnly = true,
        bool $withImageOnly = false,
    ): array;

    /** @return Product[] */
    public function findByCategoryId(string $categoryId, bool $publishedOnly = true): array;

    /** @return Product[] */
    public function findBySubcategoryId(string $subcategoryId, bool $publishedOnly = true): array;

    /**
     * Saca de una subcategoría a todos los productos que estén en ella.
     *
     * Hace falta al borrarla: `products.subcategory_id` no tiene clave ajena, así
     * que sin esto los productos se quedarían apuntando a una fila que ya no
     * existe y desaparecerían de todos los filtros sin estar borrados.
     *
     * @return int cuántos productos se han movido
     */
    public function clearSubcategory(string $subcategoryId): int;

    public function save(Product $product): void;

    public function delete(Product $product): void;
}
