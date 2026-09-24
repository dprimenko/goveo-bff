<?php

declare(strict_types=1);

namespace App\Loyalty\Infrastructure\Controller;

use App\Business\Domain\BusinessRepository;
use App\Loyalty\Application\LoyaltyAvailability;
use App\Loyalty\Domain\LoyaltyProgram;
use App\Loyalty\Domain\LoyaltyProgramRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * PUT /api/admin/businesses/{id}/loyalty — Body: {"manually_enabled": true}
 *
 * Da la tarjeta a un negocio cuya tarifa no la incluye. **Sólo suma**: apagarla
 * no se la quita a quien la tiene por su tarifa.
 *
 * Los premios no van aquí: el panel los edita por `PUT
 * /api/businesses/{id}/loyalty`, el mismo que usa el negocio, igual que edita
 * la ficha por el `PATCH` de la ficha.
 *
 * Con el permiso de editar la ficha y no con uno propio: es un ajuste más del
 * negocio, y quien puede cambiarle el nombre o la categoría puede esto.
 */
#[IsGranted('ROLE_BUSINESS_EDIT')]
#[Route('/api/admin/businesses/{id}/loyalty', name: 'admin_business_loyalty', methods: ['PUT'])]
class AdminLoyaltyController
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly LoyaltyProgramRepository $programs,
        private readonly LoyaltyAvailability $availability,
    ) {}

    public function __invoke(string $id, Request $request): Response
    {
        $business = $this->businesses->findById($id);
        if ($business === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload) || !is_bool($payload['manually_enabled'] ?? null)) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        $program = $this->programs->findByBusinessId($id) ?? new LoyaltyProgram($id);
        $program->setManuallyEnabled($payload['manually_enabled']);
        $this->programs->save($program);

        return new JsonResponse($this->availability->check($id, $program)->toArray());
    }
}
