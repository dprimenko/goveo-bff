<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use App\Moderation\Domain\ContentReport;
use App\Moderation\Domain\ContentReportRepository;
use App\Moderation\Domain\ReportStatus;
use App\Moderation\Domain\ReportTarget;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /api/admin/reports?status=open|dismissed|actioned|all&page=1&size=20
 *
 * La cola de denuncias, con lo necesario para decidir sin salir de ella: qué
 * es, una imagen, de quién, y cuántas denuncias abiertas lleva eso mismo.
 *
 * Con el permiso de moderar vídeos: quien decide qué se publica es quien decide
 * qué se retira, y un rol nuevo obligaría a tocar Keycloak para nada.
 */
#[IsGranted('ROLE_GEOSTORY_MODERATE')]
class ListContentReportsController
{
    private const MAX_SIZE = 100;

    public function __construct(
        private readonly ContentReportRepository $reports,
        private readonly Connection $db,
    ) {}

    #[Route('/api/admin/reports', name: 'admin_reports_list', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $rawStatus = (string) $request->query->get('status', ReportStatus::Open->value);
        $status    = $rawStatus === 'all' ? null : (ReportStatus::tryFrom($rawStatus) ?? ReportStatus::Open);
        $page      = max(1, (int) $request->query->get('page', 1));
        $size      = min(self::MAX_SIZE, max(1, (int) $request->query->get('size', 20)));

        $result = $this->reports->findByStatus($status, $page, $size);

        return new JsonResponse([
            'items' => array_map($this->serialize(...), $result['items']),
            'total' => $result['total'],
            'page'  => $page,
            'size'  => $size,
        ]);
    }

    private function serialize(ContentReport $r): array
    {
        return [
            'id'          => $r->getId(),
            'status'      => $r->getStatus()->value,
            'reason'      => $r->getReason()->value,
            'reason_label' => $r->getReason()->label(),
            'comment'     => $r->getComment(),
            'created_at'  => $r->getCreatedAt()->format(\DATE_ATOM),
            'resolved_at' => $r->getResolvedAt()?->format(\DATE_ATOM),
            'resolved_by' => $r->getResolvedBy(),
            'reporter'    => $this->reporter($r->getUserId()),
            'target'      => ['type' => $r->getTargetType()->value, 'id' => $r->getTargetId()]
                + $this->preview($r->getTargetType(), $r->getTargetId()),
            'owner'       => $r->getOwnerId() === null ? null : [
                'type' => $r->getOwnerType(),
                'id'   => $r->getOwnerId(),
                'name' => $this->ownerName($r->getOwnerType(), $r->getOwnerId()),
            ],
            'open_for_target' => (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM content_reports WHERE target_type = ? AND target_id = ? AND status = 'open'",
                [$r->getTargetType()->value, $r->getTargetId()],
            ),
        ];
    }

    /** @return array{label: ?string, image: ?string, url: ?string, media_type: ?string, deleted: bool} */
    private function preview(ReportTarget $type, string $id): array
    {
        $row = match ($type) {
            ReportTarget::GeoStory => $this->db->fetchAssociative(
                'SELECT title AS label, thumbnail AS image, url, media_type, deleted_at FROM geostories WHERE id = ?',
                [$id],
            ),
            ReportTarget::Product => $this->db->fetchAssociative(
                "SELECT title AS label, images->>0 AS image, NULL AS url, NULL AS media_type, deleted_at
                   FROM products WHERE id = ?",
                [$id],
            ),
            ReportTarget::Business => $this->db->fetchAssociative(
                'SELECT name AS label, avatar AS image, NULL AS url, NULL AS media_type, deleted_at FROM business WHERE id = ?',
                [$id],
            ),
            ReportTarget::Influencer => $this->db->fetchAssociative(
                'SELECT name AS label, avatar AS image, NULL AS url, NULL AS media_type, deleted_at FROM influencers WHERE id = ?',
                [$id],
            ),
        };

        return [
            'label'      => $row['label'] ?? null,
            'image'      => $row['image'] ?? null,
            'url'        => $row['url'] ?? null,
            'media_type' => $row['media_type'] ?? null,
            // Ya retirado —por otra denuncia o desde otra pantalla—: la de aquí
            // sólo queda por cerrar.
            'deleted'    => $row === false || $row['deleted_at'] !== null,
        ];
    }

    private function ownerName(?string $type, string $id): ?string
    {
        $table = $type === 'business' ? 'business' : 'influencers';

        return $this->db->fetchOne("SELECT name FROM {$table} WHERE id = ?", [$id]) ?: null;
    }

    /** Correo de quien denuncia, si tiene fila local: sirve para preguntarle. */
    private function reporter(string $userId): array
    {
        $email = $this->db->fetchOne('SELECT email FROM users WHERE id::text = ?', [$userId]) ?: null;

        return ['id' => $userId, 'email' => $email];
    }
}
