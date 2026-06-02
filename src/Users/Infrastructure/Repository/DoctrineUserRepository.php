<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Repository;

use App\Users\Domain\User;
use App\Users\Domain\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineUserRepository implements UserRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?User
    {
        return $this->em->find(User::class, $id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    public function save(User $user): void
    {
        $this->em->persist($user);
        $this->em->flush();
    }

    public function delete(User $user): void
    {
        $this->em->remove($user);
        $this->em->flush();
    }
}
