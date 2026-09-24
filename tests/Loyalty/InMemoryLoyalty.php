<?php

declare(strict_types=1);

namespace App\Tests\Loyalty;

use App\Loyalty\Domain\LoyaltyCard;
use App\Loyalty\Domain\LoyaltyCardRepository;
use App\Loyalty\Domain\LoyaltyEvent;
use App\Loyalty\Domain\LoyaltyEventRepository;
use App\Loyalty\Domain\LoyaltyProgram;
use App\Loyalty\Domain\LoyaltyProgramRepository;
use App\Loyalty\Domain\LoyaltyToken;
use App\Loyalty\Domain\LoyaltyTokenRepository;
use App\Loyalty\Domain\PlanEligibility;

/** Dobles en memoria de todo lo que toca un escaneo. */
final class InMemoryLoyalty implements LoyaltyTokenRepository, LoyaltyCardRepository, LoyaltyProgramRepository, LoyaltyEventRepository, PlanEligibility
{
    /** @var array<string, LoyaltyToken> por valor en claro */
    public array $tokens = [];

    /** @var LoyaltyCard[] */
    public array $cards = [];

    /** @var array<string, LoyaltyProgram> */
    public array $programs = [];

    /** @var LoyaltyEvent[] */
    public array $events = [];

    /** @var string[] negocios con tarifa que incluye la tarjeta */
    public array $eligible = [];

    public function findByPlainToken(string $plain): ?LoyaltyToken
    {
        return $this->tokens[$plain] ?? null;
    }

    public function findById(string $id): ?LoyaltyToken
    {
        foreach ($this->tokens as $token) {
            if ($token->getId() === $id) {
                return $token;
            }
        }

        return null;
    }

    public function save(LoyaltyToken|LoyaltyCard|LoyaltyProgram|LoyaltyEvent $entity): void
    {
        match (true) {
            $entity instanceof LoyaltyCard    => $this->cards[$entity->getId()] = $entity,
            $entity instanceof LoyaltyProgram => $this->programs[$entity->getBusinessId()] = $entity,
            $entity instanceof LoyaltyEvent   => $this->events[] = $entity,
            default                           => null,
        };
    }

    public function claim(LoyaltyToken $token, string $userId): bool
    {
        if ($token->isUsed()) {
            return false;
        }
        $token->markUsed($userId);

        return true;
    }

    public function find(string $userId, string $businessId): ?LoyaltyCard
    {
        foreach ($this->cards as $card) {
            if ($card->getUserId() === $userId && $card->getBusinessId() === $businessId) {
                return $card;
            }
        }

        return null;
    }

    public function findByUser(string $userId): array
    {
        return array_values(array_filter($this->cards, static fn (LoyaltyCard $c) => $c->getUserId() === $userId));
    }

    public function findByBusinessId(string $businessId): ?LoyaltyProgram
    {
        return $this->programs[$businessId] ?? null;
    }

    public function findByBusinessIds(array $businessIds): array
    {
        return array_intersect_key($this->programs, array_flip($businessIds));
    }

    public function includesLoyalty(string $businessId): bool
    {
        return in_array($businessId, $this->eligible, true);
    }
}
