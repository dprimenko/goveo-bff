<?php

declare(strict_types=1);

namespace App\Moderation\Application;

use App\Backoffice\Application\ReviewQueueNotifier;
use App\Moderation\Domain\ContentReport;
use App\Moderation\Domain\ContentReportRepository;
use App\Moderation\Domain\ReportReason;
use App\Moderation\Domain\ReportTarget;
use App\Shared\Domain\UuidGenerator;

/**
 * Guarda una denuncia y avisa a quien modera.
 *
 * El aviso es la mitad de la gracia: Apple pide atender las denuncias en menos
 * de 24 h (y lo prometen las condiciones), y una cola que no avisa sólo se
 * vacía si alguien se acuerda de mirarla.
 *
 * **Sin ocultado automático** por número de denuncias, a propósito: todo lo
 * publicado ya ha pasado una revisión, y esconder algo a las N denuncias le
 * daría a cualquiera —la competencia de un negocio, sin ir más lejos— un botón
 * para tumbarlo. Decide una persona.
 */
final class ReportContent
{
    /** Lo que se guarda del comentario: da para explicarse, no para pegar un libro. */
    public const MAX_COMMENT = 1000;

    public function __construct(
        private readonly ContentReportRepository $reports,
        private readonly ReportedContent $content,
        private readonly ReviewQueueNotifier $notifier,
    ) {}

    /**
     * @return ContentReport|null null si lo denunciado no existe
     */
    public function report(
        string $userId,
        ReportTarget $type,
        string $targetId,
        ReportReason $reason,
        ?string $comment = null,
    ): ?ContentReport {
        $located = $this->content->locate($type, $targetId);

        if ($located === null) {
            return null;
        }

        // Denunciar dos veces lo mismo no es otra denuncia: se devuelve la que
        // hay, y el doble toque en la app no llena la cola ni el correo.
        $existing = $this->reports->findOpen($userId, $type, $targetId, $reason);
        if ($existing !== null) {
            return $existing;
        }

        $comment = $comment !== null ? trim($comment) : '';
        $comment = $comment === '' ? null : mb_substr($comment, 0, self::MAX_COMMENT);

        $report = new ContentReport(
            UuidGenerator::generate(),
            $userId,
            $type,
            $targetId,
            $located['owner_type'],
            $located['owner_id'],
            $reason,
            $comment,
        );

        $this->reports->save($report);

        $this->notifier->contentReported(
            $report,
            $located['label'],
            $located['owner_name'],
            count($this->reports->findOpenFor($type, $targetId)),
        );

        return $report;
    }
}
