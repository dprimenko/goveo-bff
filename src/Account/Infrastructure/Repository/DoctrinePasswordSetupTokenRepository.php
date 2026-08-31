<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Repository;

use App\Account\Domain\PasswordSetupToken;
use App\Account\Domain\PasswordSetupTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrinePasswordSetupTokenRepository implements PasswordSetupTokenRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findByPlainToken(string $plain): ?PasswordSetupToken
    {
        return $this->em->getRepository(PasswordSetupToken::class)
            ->findOneBy(['tokenHash' => PasswordSetupToken::hash($plain)]);
    }

    public function save(PasswordSetupToken $token): void
    {
        $this->em->persist($token);
        $this->em->flush();
    }
}
