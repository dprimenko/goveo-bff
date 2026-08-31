<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Service;

use App\Business\Domain\BusinessManagerRepository;
use App\GeoStories\Domain\GeoStory;
use App\Influencers\Domain\InfluencerRepository;
use App\Security\GoveoUser;
use App\Users\Domain\UserRepository;

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
    ) {}

    public function userOwns(GoveoUser $user, GeoStory $story): bool
    {
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
