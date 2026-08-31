<?php

declare(strict_types=1);

namespace App\Follows\Infrastructure\Controller;

use App\Follows\Domain\Follow;
use App\Follows\Domain\FollowRepository;
use App\Follows\Domain\FollowTarget;
use App\Follows\Infrastructure\Service\FollowerCounter;
use App\Follows\Infrastructure\Service\FollowTargetLocator;
use App\Shared\Domain\UuidGenerator;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Seguir a un negocio o influencer. Idempotente: seguir algo que ya sigues
 * devuelve 200 igual, para que un doble toque en la app no dé error.
 *
 * Body: {"type": "business"|"influencer", "id": "<uuid>"}
 */
#[Route('/api/follows', name: 'follows_create', methods: ['POST'])]
class FollowController
{
    public function __construct(
        private readonly FollowRepository $follows,
        private readonly FollowTargetLocator $targets,
        private readonly FollowerCounter $counter,
        private readonly LocalUserResolver $currentUser,
    ) {}

    public function __invoke(Request $request): Response
    {
        $userId = $this->currentUser->currentId();

        if ($userId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $type    = FollowTarget::tryFromLoose(is_array($payload) ? ($payload['type'] ?? null) : null);
        $id      = is_array($payload) ? ($payload['id'] ?? null) : null;

        if ($type === null || !is_string($id) || $id === '') {
            return new JsonResponse(
                ['error' => 'type (business|influencer) and id are required'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $target = $this->targets->locate($type, $id);

        if (!$target['found']) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        if ($this->follows->find($userId, $type, $id) === null) {
            $this->follows->save(new Follow(UuidGenerator::generate(), $userId, $type, $id));
        }

        return new JsonResponse([
            'following' => true,
            'followers' => $this->counter->resolve($type, $id, $target['meta']),
        ]);
    }
}
