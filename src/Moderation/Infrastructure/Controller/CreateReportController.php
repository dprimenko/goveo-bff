<?php

declare(strict_types=1);

namespace App\Moderation\Infrastructure\Controller;

use App\Moderation\Application\ReportContent;
use App\Moderation\Domain\ReportReason;
use App\Moderation\Domain\ReportTarget;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Denunciar un vídeo, un producto o una cuenta. Idempotente como seguir:
 * denunciar dos veces lo mismo devuelve la denuncia que ya había.
 *
 * Body: {"type": "geostory"|"product"|"business"|"influencer", "id": "<uuid>",
 *        "reason": "spam"|"sexual"|"violence"|"hate"|"fraud"|"false_info"|"other",
 *        "comment"?: "…"}
 */
#[Route('/api/reports', name: 'reports_create', methods: ['POST'])]
class CreateReportController
{
    public function __construct(
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

        $type    = ReportTarget::tryFromLoose($payload['type'] ?? null);
        $id      = $payload['id'] ?? null;
        $reason  = ReportReason::tryFromUser($payload['reason'] ?? null);
        $comment = is_string($payload['comment'] ?? null) ? $payload['comment'] : null;

        if ($type === null || !is_string($id) || $id === '' || $reason === null) {
            return new JsonResponse(
                ['error' => 'type (geostory|product|business|influencer), id and reason are required'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $report = $this->reportContent->report($userId, $type, $id, $reason, $comment);

        if ($report === null) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['reported' => true, 'id' => $report->getId()], Response::HTTP_CREATED);
    }
}
