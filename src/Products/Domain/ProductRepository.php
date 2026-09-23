<?php

declare(strict_types=1);

namespace App\Products\Domain;

interface ProductRepository
{
    public function findById(string $id): ?Product;

    public function findBySlug(string $businessId, string $slug): ?Product;

    /**
     * Si ese slug ya está cogido en el negocio, **incluidos los borrados**.
     *
     * El índice único de la tabla no distingue: un producto borrado sigue
     * ocupando su slug. Y así debe ser, porque el slug es parte de una URL
     * pública que puede estar compartida, y reutilizarlo llevaría a quien
     * abriera el enlace viejo a un producto distinto.
     */
    public function slugTaken(string $businessId, string $slug): bool;

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
    /**
     * Los ids de subcategoría que tienen al menos un producto publicado.
     *
     * Lo usa la ficha pública para no enseñar un chip que no lleva a nada. Se
     * pregunta por el conjunto y no producto a producto: son dos consultas por
     * ficha y no una por chip.
     *
     * @return string[]
     */
    public function subcategoryIdsWithProducts(string $businessId): array;

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
