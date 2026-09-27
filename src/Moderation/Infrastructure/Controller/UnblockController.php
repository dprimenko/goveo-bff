<?php

declare(strict_types=1);

namespace App\Moderation\Infrastructure\Controller;

use App\Moderation\Domain\BlockTarget;
use App\Moderation\Domain\UserBlockRepository;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Desbloquear. Idempotente: si no estaba bloqueado, 200 igual.
 *
 * No toca la denuncia que dejó el bloqueo: quien modera ya la tiene en la cola,
 * y arrepentirse de bloquear no dice nada de si el contenido estaba bien.
 */
#[Route('/api/blocks/{type}/{id}', name: 'blocks_delete', methods: ['DELETE'])]
class UnblockController
{
    public function __construct(
        private readonly UserBlockRepository $blocks,
        private readonly LocalUserResolver $currentUser,
    ) {}

    public function __invoke(string $type, string $id): Response
    {
        $userId = $this->currentUser->currentId();

        if ($userId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $target = BlockTarget::tryFromLoose($type);

        if ($target === null) {
            return new JsonResponse(
                ['error' => 'type must be business or influencer'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $block = $this->blocks->find($userId, $target, $id);

        if ($block !== null) {
            $this->blocks->delete($block);
        }

        return new JsonResponse(['blocked' => false]);
    }
}
