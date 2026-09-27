<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Repository;

use App\Account\Domain\TermsAcceptance;
use App\Account\Domain\TermsAcceptanceRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineTermsAcceptanceRepository implements TermsAcceptanceRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function latestVersion(string $userId): ?string
    {
        $row = $this->em->createQueryBuilder()
            ->select('t.version')
            ->from(TermsAcceptance::class, 't')
            ->where('t.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('t.acceptedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row['version'] ?? null;
    }

    public function has(string $userId, string $version): bool
    {
        return $this->em->getRepository(TermsAcceptance::class)
            ->findOneBy(['userId' => $userId, 'version' => $version]) !== null;
    }

    public function save(TermsAcceptance $acceptance): void
    {
        $this->em->persist($acceptance);
        $this->em->flush();
    }
}
