<?php

declare(strict_types=1);

namespace App\Follows\Infrastructure\Controller;

use App\Follows\Domain\FollowRepository;
use App\Follows\Domain\FollowTarget;
use App\Follows\Infrastructure\Service\FollowerCounter;
use App\Follows\Infrastructure\Service\FollowTargetLocator;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dejar de seguir. Idempotente igual que el POST: si no lo seguías, 200.
 */
#[Route('/api/follows/{type}/{id}', name: 'follows_delete', methods: ['DELETE'])]
class UnfollowController
{
    public function __construct(
        private readonly FollowRepository $follows,
        private readonly FollowTargetLocator $targets,
        private readonly FollowerCounter $counter,
        private readonly LocalUserResolver $currentUser,
    ) {}

    public function __invoke(string $type, string $id): Response
    {
        $userId = $this->currentUser->currentId();

        if ($userId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $target = FollowTarget::tryFromLoose($type);

        if ($target === null) {
            return new JsonResponse(
                ['error' => 'type must be business or influencer'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $follow = $this->follows->find($userId, $target, $id);

        if ($follow !== null) {
            $this->follows->delete($follow);
        }

        $located = $this->targets->locate($target, $id);

        return new JsonResponse([
            'following' => false,
            'followers' => $this->counter->resolve($target, $id, $located['meta']),
        ]);
    }
}
