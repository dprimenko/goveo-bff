<?php

declare(strict_types=1);

namespace App\Business\Application;

use App\Business\Domain\Business;
use App\Categories\Domain\CategoryRepository;
use Doctrine\DBAL\Connection;

/**
 * La categoría de un negocio y lo que arrastra.
 *
 * - Sólo se acepta una a la que pueda pertenecer un negocio (ver
 *   `CategoryRepository::isAssignableToBusiness`): antes valía cualquier
 *   texto no vacío, y un id inventado dejaba el negocio fuera de todo filtro.
 * - El negocio no puede ponerse una que sólo asigna el equipo (`admin_only`,
 *   Restaurantes Top): `isSelfAssignable` es lo que miran el alta y su propia
 *   edición; el panel sigue con `isAssignable`.
 * - Sus productos van con él: llevan siempre la categoría del negocio (ver
 *   Version20260926121000), así que moverlo es moverlos.
 */
class BusinessCategory
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly Connection $db,
    ) {}

    public function isAssignable(string $categoryId): bool
    {
        return $this->categories->isAssignableToBusiness($categoryId);
    }

    /** Lo que puede elegir el propio negocio: asignable y no reservada al equipo. */
    public function isSelfAssignable(string $categoryId): bool
    {
        return $this->isAssignable($categoryId)
            && !$this->db->fetchOne('SELECT admin_only FROM categories WHERE id = ?', [$categoryId]);
    }

    /** Tras guardar el negocio: iguala la de sus productos. */
    public function syncProducts(Business $business): void
    {
        $this->db->executeStatement(
            'UPDATE products SET category_id = ?, updated_at = NOW()
              WHERE business_id = ? AND category_id IS DISTINCT FROM ?',
            [$business->getCategoryId(), $business->getId(), $business->getCategoryId()],
        );
    }
}
