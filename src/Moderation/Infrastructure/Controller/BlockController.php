<?php

declare(strict_types=1);

namespace App\Moderation\Infrastructure\Controller;

use App\Moderation\Application\ReportContent;
use App\Moderation\Domain\BlockTarget;
use App\Moderation\Domain\ReportReason;
use App\Moderation\Domain\ReportTarget;
use App\Moderation\Domain\UserBlock;
use App\Moderation\Domain\UserBlockRepository;
use App\Shared\Domain\UuidGenerator;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bloquear a un negocio o influencer: deja de salir en los feeds, la búsqueda
 * y el mapa de quien bloquea (ver «Moderación» en CLAUDE.md).
 *
 * **Y avisa a quien modera**, que es lo que pide Apple (guideline 1.2): el
 * bloqueo deja una denuncia con motivo `blocked`. Si se bloquea desde un vídeo
 * o un producto, la denuncia apunta a ese contenido —`content`—, que es lo que
 * hizo saltar a quien bloquea; si no, a la cuenta.
 *
 * Idempotente: bloquear dos veces no duplica ni vuelve a avisar.
 *
 * Body: {"type": "business"|"influencer", "id": "<uuid>",
 *        "content"?: {"type": "geostory"|"product", "id": "<uuid>"}}
 */
#[Route('/api/blocks', name: 'blocks_create', methods: ['POST'])]
class BlockController
{
    public function __construct(
        private readonly UserBlockRepository $blocks,
        private readonly ReportContent $reportContent,
        private readonly LocalUserResolver $currentUser,
    ) {}

    public function __invoke(Request $request): Response
    {
        $userId = $this->currentUser->currentId();

        if ($userId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $payload = is_array($payload) ? $payload : [];

        $type = BlockTarget::tryFromLoose($payload['type'] ?? null);
        $id   = $payload['id'] ?? null;

        if ($type === null || !is_string($id) || $id === '') {
            return new JsonResponse(
                ['error' => 'type (business|influencer) and id are required'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($this->blocks->find($userId, $type, $id) !== null) {
            return new JsonResponse(['blocked' => true]);
        }

        // La denuncia primero: comprueba de paso que la cuenta existe, y así no
        // se guarda un bloqueo a nada.
        [$reportType, $reportId] = $this->reportTarget($type, $id, $payload['content'] ?? null);

        $report = $this->reportContent->report($userId, $reportType, $reportId, ReportReason::Blocked);

        if ($report === null && $reportType !== ReportTarget::from($type->value)) {
            // El contenido ya no está: se denuncia la cuenta, que es lo que se bloquea.
            $report = $this->reportContent->report($userId, ReportTarget::from($type->value), $id, ReportReason::Blocked);
        }

        if ($report === null) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $this->blocks->save(new UserBlock(UuidGenerator::generate(), $userId, $type, $id));

        return new JsonResponse(['blocked' => true], Response::HTTP_CREATED);
    }

    /**
     * @return array{0: ReportTarget, 1: string}
     */
    private function reportTarget(BlockTarget $type, string $id, mixed $content): array
    {
        if (is_array($content)) {
            $contentType = ReportTarget::tryFromLoose($content['type'] ?? null);
            $contentId   = $content['id'] ?? null;

            if (in_array($contentType, [ReportTarget::GeoStory, ReportTarget::Product], true)
                && is_string($contentId) && $contentId !== '') {
                return [$contentType, $contentId];
            }
        }

        return [ReportTarget::from($type->value), $id];
    }
}
