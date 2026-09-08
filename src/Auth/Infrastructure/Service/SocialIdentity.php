<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Service;

/**
 * La identidad que un proveedor social afirma, ya verificada.
 *
 * `$subject` es el `sub` del proveedor: el identificador con el que Keycloak
 * guarda el vínculo federado, y el único que no cambia si el usuario se cambia
 * el correo o el nombre.
 */
final readonly class SocialIdentity
{
    public function __construct(
        public string $subject,
        public string $email,
        public bool $emailVerified,
    ) {}
}
