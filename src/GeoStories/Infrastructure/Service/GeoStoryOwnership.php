<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Service;

use App\Business\Domain\BusinessManagerRepository;
use App\GeoStories\Domain\GeoStory;
use App\Influencers\Domain\InfluencerRepository;
use App\Security\GoveoUser;
use App\Users\Domain\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Resolves whether the authenticated user owns a GeoStory — an influencer owns
 * their own posts; a business manager owns the posts of any store they manage.
 * The JWT `sub` (Keycloak) is bridged to the local users.id by email.
 */
final class GeoStoryOwnership
{
    public function __construct(
        private readonly InfluencerRepository $influencers,
        private readonly BusinessManagerRepository $businessManagers,
        private readonly UserRepository $users,
        private readonly Security $security,
    ) {}

    /**
     * @param bool $allowBackoffice deja pasar también a quien modere vídeos en
     *                              el panel, aunque no sea suyo. Apagado por
     *                              defecto: se enciende sólo donde el panel
     *                              tiene que poder editar, y así el permiso no
     *                              abre de paso todo lo que use este servicio.
     */
    public function userOwns(GoveoUser $user, GeoStory $story, bool $allowBackoffice = false): bool
    {
        if ($allowBackoffice && $this->security->isGranted('ROLE_GEOSTORY_MODERATE')) {
            return true;
        }

        $userId = ($user->getEmail() !== null
            ? $this->users->findByEmail($user->getEmail())?->getId()
            : null) ?? $user->getId();

        if ($story->getInfluencerId() !== null) {
            $influencer = $this->influencers->findByUserId($userId);

            return $influencer !== null && $influencer->getId() === $story->getInfluencerId();
        }

        if ($story->getBusinessId() !== null) {
            $managedBizIds = array_map(
                static fn ($m) => $m->getBusinessId(),
                $this->businessManagers->findByUserId($userId),
            );

            return in_array($story->getBusinessId(), $managedBizIds, true);
        }

        return false;
    }
}
