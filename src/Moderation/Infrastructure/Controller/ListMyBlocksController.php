<?php

declare(strict_types=1);

namespace App\Moderation\Infrastructure\Controller;

use App\Moderation\Domain\UserBlockRepository;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Todo lo que ha bloqueado el usuario, con nombre y avatar. La app lo carga una
 * vez por sesión, como los follows, y con eso quita del feed lo bloqueado al
 * instante sin esperar a la siguiente página.
 *
 * → {business: [{id, name, avatar}], influencer: [{id, name, avatar}]}
 */
#[Route('/api/blocks', name: 'blocks_list', methods: ['GET'])]
class ListMyBlocksController
{
    public function __construct(
        private readonly UserBlockRepository $blocks,
        private readonly LocalUserResolver $currentUser,
    ) {}

    public function __invoke(): Response
    {
        $userId = $this->currentUser->currentId();

        if ($userId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse($this->blocks->findByUser($userId));
    }
}
