<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use App\Moderation\Application\ContentTakedown;
use App\Moderation\Domain\ContentReport;
use App\Moderation\Domain\ContentReportRepository;
use App\Moderation\Domain\ReportStatus;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * PUT /api/admin/reports/{id}/dismiss       no había nada que quitar
 * PUT /api/admin/reports/{id}/remove        retira lo denunciado (vídeo, producto o cuenta)
 * PUT /api/admin/reports/{id}/remove-owner  retira la cuenta que lo publicó, con todo lo suyo
 *
 * Cada decisión **cierra todas las denuncias abiertas sobre lo mismo**: si un
 * vídeo lleva cinco, se ha mirado una vez y las cinco están atendidas. Con
 * `remove-owner`, también las de cualquier cosa de esa cuenta.
 *
 * Retirar archiva (ver `ContentTakedown`), así que se deshace desde Vídeos o
 * Negocios como cualquier otro archivado.
 */
#[IsGranted('ROLE_GEOSTORY_MODERATE')]
class ResolveContentReportController
{
    public function __construct(
        private readonly ContentReportRepository $reports,
        private readonly ContentTakedown $takedown,
        private readonly Security $security,
    ) {}

    #[Route('/api/admin/reports/{id}/dismiss', name: 'admin_reports_dismiss', methods: ['PUT'])]
    public function dismiss(string $id): Response
    {
        $report = $this->reports->findById($id);

        if ($report === null) {
            return new JsonResponse(['error' => 'Report not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->close($report, ReportStatus::Dismissed, ownerWide: false);
    }

    #[Route('/api/admin/reports/{id}/remove', name: 'admin_reports_remove', methods: ['PUT'])]
    public function remove(string $id): Response
    {
        $report = $this->reports->findById($id);

        if ($report === null) {
            return new JsonResponse(['error' => 'Report not found.'], Response::HTTP_NOT_FOUND);
        }

        // Si ya no existe, se cierra igual: lo que se quería es que no se viera.
        $this->takedown->remove($report->getTargetType(), $report->getTargetId());

        return $this->close($report, ReportStatus::Actioned, ownerWide: false);
    }

    #[Route('/api/admin/reports/{id}/remove-owner', name: 'admin_reports_remove_owner', methods: ['PUT'])]
    public function removeOwner(string $id): Response
    {
        $report = $this->reports->findById($id);

        if ($report === null) {
            return new JsonResponse(['error' => 'Report not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($report->getOwnerId() === null || $report->getOwnerType() === null) {
            return new JsonResponse(['error' => 'Report has no owner.'], Response::HTTP_CONFLICT);
        }

        $this->takedown->removeAccount($report->getOwnerType(), $report->getOwnerId());

        return $this->close($report, ReportStatus::Actioned, ownerWide: true);
    }

    private function close(ContentReport $report, ReportStatus $status, bool $ownerWide): JsonResponse
    {
        $by      = $this->security->getUser()?->getUserIdentifier();
        $related = $ownerWide
            ? $this->reports->findOpenByOwner((string) $report->getOwnerType(), (string) $report->getOwnerId())
            : $this->reports->findOpenFor($report->getTargetType(), $report->getTargetId());

        $closed = 0;
        foreach ([$report, ...$related] as $r) {
            if ($r->isOpen()) {
                $r->resolve($status, $by);
                $this->reports->save($r);
                $closed++;
            }
        }

        return new JsonResponse([
            'id'     => $report->getId(),
            'status' => $report->getStatus()->value,
            'closed' => $closed,
        ]);
    }
}
