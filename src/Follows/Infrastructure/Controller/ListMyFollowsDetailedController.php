<?php

declare(strict_types=1);

namespace App\Follows\Infrastructure\Controller;

use App\Follows\Domain\FollowRepository;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A quién sigue el usuario, con nombre y avatar, para la lista de «Siguiendo».
 * `GET /api/follows` se queda con los ids solos: es lo que la app carga al
 * arrancar para pintar los botones, y no necesita más.
 *
 * → {business: [{id, name, avatar, slug, city}], influencer: [{id, name, username, avatar}]}
 */
#[Route('/api/follows/list', name: 'follows_list_detailed', methods: ['GET'])]
class ListMyFollowsDetailedController
{
    public function __construct(
        private readonly FollowRepository $follows,
        private readonly LocalUserResolver $currentUser,
    ) {}

    public function __invoke(): Response
    {
        $userId = $this->currentUser->currentId();

        if ($userId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse($this->follows->findByUserWithDetails($userId));
    }
}
