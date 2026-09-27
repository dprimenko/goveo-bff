<?php

declare(strict_types=1);

namespace App\Moderation\Infrastructure\Repository;

use App\Moderation\Domain\ContentReport;
use App\Moderation\Domain\ContentReportRepository;
use App\Moderation\Domain\ReportReason;
use App\Moderation\Domain\ReportStatus;
use App\Moderation\Domain\ReportTarget;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineContentReportRepository implements ContentReportRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?ContentReport
    {
        return $this->em->getRepository(ContentReport::class)->find($id);
    }

    public function findOpen(string $userId, ReportTarget $type, string $targetId, ReportReason $reason): ?ContentReport
    {
        return $this->em->getRepository(ContentReport::class)->findOneBy([
            'userId'     => $userId,
            'targetType' => $type,
            'targetId'   => $targetId,
            'reason'     => $reason,
            'status'     => ReportStatus::Open,
        ]);
    }

    public function findOpenFor(ReportTarget $type, string $targetId): array
    {
        return $this->em->getRepository(ContentReport::class)->findBy([
            'targetType' => $type,
            'targetId'   => $targetId,
            'status'     => ReportStatus::Open,
        ]);
    }

    public function findOpenByOwner(string $ownerType, string $ownerId): array
    {
        return $this->em->getRepository(ContentReport::class)->findBy([
            'ownerType' => $ownerType,
            'ownerId'   => $ownerId,
            'status'    => ReportStatus::Open,
        ]);
    }

    public function findByStatus(?ReportStatus $status, int $page, int $size): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('r')
            ->from(ContentReport::class, 'r');

        if ($status !== null) {
            $qb->where('r.status = :status')->setParameter('status', $status);
        }

        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        // Lo más antiguo primero mientras esté abierto: es lo que más lleva
        // esperando. Lo cerrado, al revés, que se consulta para ver lo último.
        $items = $qb
            ->orderBy('r.createdAt', $status === ReportStatus::Open ? 'ASC' : 'DESC')
            ->setFirstResult(($page - 1) * $size)
            ->setMaxResults($size)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function save(ContentReport $report): void
    {
        $this->em->persist($report);
        $this->em->flush();
    }
}
