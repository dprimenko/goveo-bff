<?php

declare(strict_types=1);

namespace App\Categories\Domain;

interface CategoryRepository
{
    public function findById(string $id): ?Category;
    public function findBySlug(string $slug): ?Category;
    /** @return Category[] */
    public function findAll(): array;
    /**
     * Los ids pedidos **más todo lo que cuelga de ellos**: filtrar por un grupo
     * es filtrar por sus subcategorías, y un negocio está en la hoja.
     *
     * @param string[] $ids
     * @return string[]
     */
    public function withDescendants(array $ids): array;

    /**
     * Las categorías que cuentan como «Turismo»: los grupos de esa sección con
     * lo que cuelga de ellos, las del partner ibiza y las de influencer de
     * siempre (lugares, cultura, naturaleza, eventos). «Comercio local» es
     * todo lo demás, para que una categoría nueva no se quede fuera de ambos.
     *
     * @return string[]
     */
    public function tourismIds(): array;

    /**
     * Si un negocio puede estar en esta categoría: existe, no está borrada, no
     * es sólo de influencer y es un grupo, cuelga de uno o es de las de primer
     * nivel que no se han reorganizado (ibiza). Los tipos de evento no: son de
     * vídeos.
     */
    public function isAssignableToBusiness(string $id): bool;

    public function save(Category $category): void;
    public function delete(Category $category): void;
}
