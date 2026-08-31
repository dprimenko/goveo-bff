<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Controller;

use App\Business\Domain\BusinessManagerRepository;
use App\Business\Domain\BusinessRepository;
use App\Influencers\Domain\InfluencerRepository;
use App\Security\GoveoUser;
use App\Users\Domain\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Returns the authenticated user's identity derived from the JWT.
 * Route is under /api/* so it requires IS_AUTHENTICATED_FULLY (see security.yaml).
 */
#[Route('/api/auth/me', name: 'auth_me', methods: ['GET'])]
class MeController
{
    public function __construct(
        private readonly Security $security,
        private readonly InfluencerRepository $influencers,
        private readonly BusinessManagerRepository $businessManagers,
        private readonly BusinessRepository $businesses,
        private readonly UserRepository $users,
    ) {}

    public function __invoke(): Response
    {
        /** @var GoveoUser $user */
        $user = $this->security->getUser();

        if (!$user instanceof GoveoUser) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // JWT `sub` (Keycloak) != local users.id (Supabase UUID). Bridge by email.
        $localUserId = ($user->getEmail() !== null
            ? $this->users->findByEmail($user->getEmail())?->getId()
            : null) ?? $user->getId();

        // Which content-owner identities does this user carry? Drives the
        // upload flow (influencer vs business, category set, location source).
        $influencer  = $this->influencers->findByUserId($localUserId);
        $businessIds = array_values(array_map(
            static fn ($m) => $m->getBusinessId(),
            $this->businessManagers->findByUserId($localUserId),
        ));

        // Display name of the chosen profile (influencer first, then store),
        // falling back to the Keycloak name in the app.
        $profileName = $influencer?->getName();
        if ($profileName === null && !empty($businessIds)) {
            $profileName = $this->businesses->findById($businessIds[0])?->getName();
        }

        return new JsonResponse([
            'id'            => $user->getId(),
            'email'         => $user->getEmail(),
            'roles'         => $user->getRoles(),
            'firstName'     => $user->getFirstName(),
            'lastName'      => $user->getLastName(),
            'name'          => $user->getName(),
            'influencer_id' => $influencer?->getId(),
            'business_ids'  => $businessIds,
            'profile_name'  => $profileName,
        ]);
    }
}
