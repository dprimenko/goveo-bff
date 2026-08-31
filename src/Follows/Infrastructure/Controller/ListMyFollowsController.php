<?php

declare(strict_types=1);

namespace App\Follows\Infrastructure\Controller;

use App\Follows\Domain\FollowRepository;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Todo lo que sigue el usuario, en una sola llamada: la app lo cachea y con eso
 * pinta el estado de cualquier botón de seguir (perfiles y feed) sin pedir nada
 * más por cada tarjeta.
 */
#[Route('/api/follows', name: 'follows_list', methods: ['GET'])]
class ListMyFollowsController
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

        return new JsonResponse($this->follows->findIdsByUser($userId));
    }
}
