<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Business\Domain\BusinessManagerRepository;
use App\Influencers\Domain\InfluencerRepository;
use App\Security\GoveoUser;
use App\Users\Domain\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * ¿El usuario autenticado es el dueño de este perfil?
 *
 * Lo usan los endpoints públicos que enseñan **más** a quien es el dueño: el
 * listado de vídeos, por ejemplo, incluye los que están pendientes de validar
 * sólo si quien pregunta es su autor, para que pueda gestionarlos.
 *
 * Sin token —el caso normal en `/public/`— devuelve `false` sin tocar la base.
 *
 * El puente por correo es el mismo que usa `MeController`: el `sub` del JWT es
 * el id en Keycloak, que no coincide con `users.id` (heredado de Supabase).
 */
final class ProfileOwnership
{
    public function __construct(
        private readonly Security $security,
        private readonly UserRepository $users,
        private readonly InfluencerRepository $influencers,
        private readonly BusinessManagerRepository $businessManagers,
    ) {}

    public function ownsInfluencer(?string $influencerId): bool
    {
        if ($influencerId === null) {
            return false;
        }

        $userId = $this->localUserId();

        return $userId !== null
            && $this->influencers->findByUserId($userId)?->getId() === $influencerId;
    }

    public function ownsBusiness(?string $businessId): bool
    {
        if ($businessId === null) {
            return false;
        }

        $userId = $this->localUserId();
        if ($userId === null) {
            return false;
        }

        foreach ($this->businessManagers->findByUserId($userId) as $manager) {
            if ($manager->getBusinessId() === $businessId) {
                return true;
            }
        }

        return false;
    }

    private function localUserId(): ?string
    {
        $user = $this->security->getUser();
        if (!$user instanceof GoveoUser || $user->getEmail() === null) {
            return null;
        }

        return $this->users->findByEmail($user->getEmail())?->getId();
    }
}
