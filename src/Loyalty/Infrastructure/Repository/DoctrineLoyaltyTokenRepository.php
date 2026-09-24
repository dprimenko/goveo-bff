<?php

declare(strict_types=1);

namespace App\Loyalty\Infrastructure\Repository;

use App\Loyalty\Domain\LoyaltyToken;
use App\Loyalty\Domain\LoyaltyTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineLoyaltyTokenRepository implements LoyaltyTokenRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findByPlainToken(string $plain): ?LoyaltyToken
    {
        return $this->em->getRepository(LoyaltyToken::class)
            ->findOneBy(['tokenHash' => LoyaltyToken::hash($plain)]);
    }

    public function findById(string $id): ?LoyaltyToken
    {
        return $this->em->find(LoyaltyToken::class, $id);
    }

    public function save(LoyaltyToken $token): void
    {
        $this->em->persist($token);
        $this->em->flush();
    }

    public function claim(LoyaltyToken $token, string $userId): bool
    {
        $now = new \DateTimeImmutable();

        // La condición `used_at IS NULL` va en el propio UPDATE: la base
        // serializa las dos escrituras y sólo una encuentra la fila libre.
        $claimed = $this->em->createQueryBuilder()
            ->update(LoyaltyToken::class, 't')
            ->set('t.usedAt', ':now')
            ->set('t.usedByUserId', ':userId')
            ->where('t.id = :id')
            ->andWhere('t.usedAt IS NULL')
            ->setParameter('now', $now, 'datetimetz_immutable')
            ->setParameter('userId', $userId)
            ->setParameter('id', $token->getId())
            ->getQuery()
            ->execute();

        if ($claimed !== 1) {
            return false;
        }

        // El UPDATE no pasa por la entidad; se refleja a mano para que quien la
        // tenga cargada no la vea todavía libre.
        $token->markUsed($userId, $now);

        return true;
    }
}
