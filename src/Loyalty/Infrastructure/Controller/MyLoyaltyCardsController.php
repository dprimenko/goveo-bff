<?php

declare(strict_types=1);

namespace App\Loyalty\Infrastructure\Controller;

use App\Business\Domain\BusinessRepository;
use App\Loyalty\Application\LoyaltyAvailability;
use App\Loyalty\Domain\LoyaltyCardRepository;
use App\Loyalty\Domain\LoyaltyProgramRepository;
use App\Loyalty\Infrastructure\Service\LoyaltyPresenter;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Las tarjetas del usuario: todas, o la de un negocio. */
#[Route('/api/loyalty/cards', name: 'loyalty_cards_')]
class MyLoyaltyCardsController
{
    public function __construct(
        private readonly LoyaltyCardRepository $cards,
        private readonly LoyaltyProgramRepository $programs,
        private readonly BusinessRepository $businesses,
        private readonly LoyaltyAvailability $availability,
        private readonly LoyaltyPresenter $presenter,
        private readonly LocalUserResolver $currentUser,
    ) {}

    /**
     * Las tarjetas en las que tiene o ha tenido sellos, la última usada
     * primero. Salen también las que ha dejado a cero al canjear: sigue siendo
     * cliente de ese negocio.
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        $userId = $this->currentUser->currentId();
        if ($userId === null) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $cards    = $this->cards->findByUser($userId);
        $programs = $this->programs->findByBusinessIds(array_map(
            static fn ($card) => $card->getBusinessId(),
            $cards,
        ));

        $items = [];
        foreach ($cards as $card) {
            $business = $this->businesses->findById($card->getBusinessId());
            if ($business === null || $business->isDeleted()) {
                continue;
            }

            $program = $programs[$card->getBusinessId()] ?? null;
            $items[] = $this->presenter->card(
                $business,
                $program,
                $this->availability->check($business->getId(), $program),
                $card,
            );
        }

        return new JsonResponse(['items' => $items]);
    }

    /** La del negocio, aunque aún no tenga ningún sello en él. */
    #[Route('/{businessId}', name: 'get', methods: ['GET'])]
    public function get(string $businessId): Response
    {
        $userId = $this->currentUser->currentId();
        if ($userId === null) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $business = $this->businesses->findById($businessId);
        if ($business === null || $business->isDeleted()) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $program = $this->programs->findByBusinessId($businessId);

        return new JsonResponse($this->presenter->card(
            $business,
            $program,
            $this->availability->check($businessId, $program),
            $this->cards->find($userId, $businessId),
        ));
    }
}
