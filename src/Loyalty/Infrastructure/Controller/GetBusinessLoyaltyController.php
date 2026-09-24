<?php

declare(strict_types=1);

namespace App\Loyalty\Infrastructure\Controller;

use App\Business\Domain\BusinessRepository;
use App\Loyalty\Application\LoyaltyAvailability;
use App\Loyalty\Domain\LoyaltyProgramRepository;
use App\Loyalty\Infrastructure\Service\LoyaltyPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Si un negocio tiene tarjeta y qué premios da, para cualquiera.
 *
 * Es lo que la app enseña a quien llega desde un QR sin haber iniciado sesión:
 * la tarjeta del negocio, vacía, con el sello pendiente de aplicar. Sin sesión
 * no se sabe si llega a los premios, así que no llevan `redeemable`.
 */
#[Route('/public/businesses/{id}/loyalty', name: 'public_business_loyalty', methods: ['GET'])]
class GetBusinessLoyaltyController
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly LoyaltyProgramRepository $programs,
        private readonly LoyaltyAvailability $availability,
        private readonly LoyaltyPresenter $presenter,
    ) {}

    public function __invoke(string $id): Response
    {
        $business = $this->businesses->findById($id);
        if ($business === null || $business->isDeleted()) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $program = $this->programs->findByBusinessId($id);
        $status  = $this->availability->check($id, $program);

        // La misma forma que la tarjeta del usuario, con cero sellos: la app la
        // pinta igual con sesión o sin ella.
        return new JsonResponse($this->presenter->card($business, $program, $status, null, forUser: false));
    }
}
