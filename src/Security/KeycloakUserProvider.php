<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class KeycloakUserProvider implements UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // The identifier is the Keycloak subject (user UUID).
        // We return a lightweight user object — no DB lookup required here
        // because the JWT already contains all needed claims.
        return new GoveoUser(id: $identifier, email: null);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return GoveoUser::class === $class || is_subclass_of($class, GoveoUser::class);
    }
}
