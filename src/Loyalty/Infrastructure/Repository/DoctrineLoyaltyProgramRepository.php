<?php

declare(strict_types=1);

namespace App\Loyalty\Infrastructure\Repository;

use App\Loyalty\Domain\LoyaltyProgram;
use App\Loyalty\Domain\LoyaltyProgramRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineLoyaltyProgramRepository implements LoyaltyProgramRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findByBusinessId(string $businessId): ?LoyaltyProgram
    {
        return $this->em->find(LoyaltyProgram::class, $businessId);
    }

    public function findByBusinessIds(array $businessIds): array
    {
        if ($businessIds === []) {
            return [];
        }

        $programs = $this->em->getRepository(LoyaltyProgram::class)->findBy(['businessId' => $businessIds]);

        $byBusiness = [];
        foreach ($programs as $program) {
            $byBusiness[$program->getBusinessId()] = $program;
        }

        return $byBusiness;
    }

    public function save(LoyaltyProgram $program): void
    {
        $this->em->persist($program);
        $this->em->flush();
    }
}
