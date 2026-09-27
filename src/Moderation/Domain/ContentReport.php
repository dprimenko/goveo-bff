<?php

declare(strict_types=1);

namespace App\Moderation\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Una denuncia de un usuario sobre algo publicado.
 *
 * `owner_type` / `owner_id` es **de quién** es lo denunciado —el negocio o el
 * influencer que lo subió—, resuelto al denunciar. Con eso el panel agrupa las
 * denuncias de una misma cuenta sin tener que ir a buscar cada vídeo, y si el
 * vídeo se borra después la denuncia sigue diciendo de quién era.
 *
 * `user_id` es el id **local**, como en `user_follows` (ver `LocalUserResolver`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'content_reports')]
#[ORM\Index(name: 'idx_content_reports_status', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_content_reports_target', columns: ['target_type', 'target_id'])]
#[ORM\Index(name: 'idx_content_reports_user', columns: ['user_id'])]
class ContentReport
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'user_id', type: 'guid')]
    private string $userId;

    #[ORM\Column(name: 'target_type', type: 'string', length: 20, enumType: ReportTarget::class)]
    private ReportTarget $targetType;

    #[ORM\Column(name: 'target_id', type: 'guid')]
    private string $targetId;

    #[ORM\Column(name: 'owner_type', type: 'string', length: 20, nullable: true)]
    private ?string $ownerType;

    #[ORM\Column(name: 'owner_id', type: 'guid', nullable: true)]
    private ?string $ownerId;

    #[ORM\Column(type: 'string', length: 20, enumType: ReportReason::class)]
    private ReportReason $reason;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment;

    #[ORM\Column(type: 'string', length: 20, enumType: ReportStatus::class)]
    private ReportStatus $status;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'resolved_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** Quién la cerró en el panel (el `sub` de su token): para poder preguntarle. */
    #[ORM\Column(name: 'resolved_by', type: 'string', length: 255, nullable: true)]
    private ?string $resolvedBy = null;

    public function __construct(
        string $id,
        string $userId,
        ReportTarget $targetType,
        string $targetId,
        ?string $ownerType,
        ?string $ownerId,
        ReportReason $reason,
        ?string $comment = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id         = $id;
        $this->userId     = $userId;
        $this->targetType = $targetType;
        $this->targetId   = $targetId;
        $this->ownerType  = $ownerType;
        $this->ownerId    = $ownerId;
        $this->reason     = $reason;
        $this->comment    = $comment;
        $this->status     = ReportStatus::Open;
        $this->createdAt  = $createdAt ?? new \DateTimeImmutable();
    }

    public function resolve(ReportStatus $status, ?string $by): void
    {
        $this->status     = $status;
        $this->resolvedAt = new \DateTimeImmutable();
        $this->resolvedBy = $by;
    }

    public function getId(): string                      { return $this->id; }
    public function getUserId(): string                  { return $this->userId; }
    public function getTargetType(): ReportTarget        { return $this->targetType; }
    public function getTargetId(): string                { return $this->targetId; }
    public function getOwnerType(): ?string              { return $this->ownerType; }
    public function getOwnerId(): ?string                { return $this->ownerId; }
    public function getReason(): ReportReason            { return $this->reason; }
    public function getComment(): ?string                { return $this->comment; }
    public function getStatus(): ReportStatus            { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable   { return $this->createdAt; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
    public function getResolvedBy(): ?string             { return $this->resolvedBy; }
    public function isOpen(): bool                       { return $this->status === ReportStatus::Open; }
}
