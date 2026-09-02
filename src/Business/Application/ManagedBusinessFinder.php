<?php

declare(strict_types=1);

namespace App\Business\Application;

use App\Business\Domain\Business;
use App\Business\Domain\BusinessManagerRepository;
use App\Business\Domain\BusinessRepository;
use App\Users\Infrastructure\Service\LocalUserResolver;

/**
 * El negocio que gestiona quien hace la petición, o nada.
 *
 * Acepta id o slug, porque las dos formas circulan por la app. Y devuelve `null`
 * tanto si el negocio no existe como si existe pero es de otro: el que pregunta
 * no debe poder distinguir un caso del otro, porque un 403 confirmaría que ese
 * id está dado de alta.
 *
 * Estaba repetido en cada endpoint de gestión —imágenes del negocio, alta de
 * producto, edición— y es justo el sitio donde un descuido se convierte en que
 * alguien edite la tienda de otro.
 */
final class ManagedBusinessFinder
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly BusinessManagerRepository $managers,
        private readonly LocalUserResolver $currentUser,
    ) {}

    public function find(string $idOrSlug): ?Business
    {
        $userId = $this->currentUser->currentId();
        if ($userId === null) {
            return null;
        }

        $business = $this->businesses->findById($idOrSlug)
            ?? $this->businesses->findBySlug($idOrSlug);

        if ($business === null) {
            return null;
        }

        return $this->managers->findByUserAndBusiness($userId, $business->getId()) !== null
            ? $business
            : null;
    }

    /** Si hay sesión. Sirve para distinguir un 401 de un 404. */
    public function hasSession(): bool
    {
        return $this->currentUser->currentId() !== null;
    }
}
