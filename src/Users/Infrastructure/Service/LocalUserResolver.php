<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Service;

use App\Security\GoveoUser;
use App\Users\Domain\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * El `sub` del JWT (Keycloak) no es el `users.id` local (UUID heredado de
 * Supabase); el puente se hace por email. Esta lógica estaba repetida en
 * MeController, CreateGeoStoryController y GeoStoryOwnership — aquí queda
 * en un solo sitio para lo nuevo.
 */
final class LocalUserResolver
{
    public function __construct(
        private readonly Security $security,
        private readonly UserRepository $users,
    ) {}

    /** Id local del usuario autenticado, o null si no hay sesión. */
    public function currentId(): ?string
    {
        $user = $this->security->getUser();

        if (!$user instanceof GoveoUser) {
            return null;
        }

        return ($user->getEmail() !== null
            ? $this->users->findByEmail($user->getEmail())?->getId()
            : null) ?? $user->getId();
    }
}
