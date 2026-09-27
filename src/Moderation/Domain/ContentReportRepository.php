<?php

declare(strict_types=1);

namespace App\Moderation\Domain;

interface ContentReportRepository
{
    public function findById(string $id): ?ContentReport;

    /**
     * La denuncia abierta que ya hizo este usuario sobre esto y por el mismo
     * motivo, si la hay: una segunda igual no añade nada a la cola, sólo ruido.
     * Por el mismo motivo porque bloquear algo que ya se había denunciado
     * **sí** tiene que avisar: es otra cosa y Apple lo pide.
     */
    public function findOpen(string $userId, ReportTarget $type, string $targetId, ReportReason $reason): ?ContentReport;

    /**
     * Las abiertas sobre lo mismo, para cerrarlas todas de una vez: si el
     * vídeo se retira, las otras cinco denuncias de ese vídeo ya están atendidas.
     *
     * @return ContentReport[]
     */
    public function findOpenFor(ReportTarget $type, string $targetId): array;

    /**
     * Las abiertas sobre cualquier cosa de una cuenta: al retirarla entera,
     * todas quedan atendidas.
     *
     * @return ContentReport[]
     */
    public function findOpenByOwner(string $ownerType, string $ownerId): array;

    /**
     * @return array{items: ContentReport[], total: int}
     */
    public function findByStatus(?ReportStatus $status, int $page, int $size): array;

    public function save(ContentReport $report): void;
}
