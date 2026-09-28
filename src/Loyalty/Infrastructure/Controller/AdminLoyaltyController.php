<?php

declare(strict_types=1);

namespace App\Loyalty\Infrastructure\Controller;

use App\Business\Domain\BusinessRepository;
use App\Loyalty\Application\LoyaltyAvailability;
use App\Loyalty\Domain\LoyaltyCard;
use App\Loyalty\Domain\LoyaltyEventRepository;
use App\Loyalty\Domain\LoyaltyProgram;
use App\Loyalty\Domain\LoyaltyProgramRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * La tarjeta de un negocio vista desde el panel.
 *
 * GET  /api/admin/businesses/{id}/loyalty — estado, premios y cómo se usa.
 * PUT  /api/admin/businesses/{id}/loyalty — Body: {"manually_enabled": true, "active": true}
 *
 * - `manually_enabled`: da la tarjeta a un negocio cuya tarifa no la incluye.
 *   **Sólo suma**: apagarla no se la quita a quien la tiene por su tarifa.
 * - `active`: la enciende o la apaga por el negocio, por si se le olvida. Con
 *   la misma regla que el negocio: encenderla pide derecho y algún premio.
 *
 * Las dos son opcionales; al menos una.
 *
 * Los premios no se cambian aquí: el panel los edita por `PUT
 * /api/businesses/{id}/loyalty`, el mismo que usa el negocio, igual que edita
 * la ficha por el `PATCH` de la ficha.
 *
 * Con el permiso de editar la ficha y no con uno propio: es un ajuste más del
 * negocio, y quien puede cambiarle el nombre o la categoría puede esto.
 */
#[IsGranted('ROLE_BUSINESS_EDIT')]
#[Route('/api/admin/businesses/{id}/loyalty', name: 'admin_business_loyalty_')]
class AdminLoyaltyController
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly LoyaltyProgramRepository $programs,
        private readonly LoyaltyEventRepository $events,
        private readonly LoyaltyAvailability $availability,
    ) {}

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(string $id): Response
    {
        if ($this->businesses->findById($id) === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serialize($id, $this->programs->findByBusinessId($id)));
    }

    #[Route('', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request): Response
    {
        if ($this->businesses->findById($id) === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $manual  = is_array($payload) ? ($payload['manually_enabled'] ?? null) : null;
        $active  = is_array($payload) ? ($payload['active'] ?? null) : null;
        if (!is_array($payload) || ($manual === null && $active === null)
            || ($manual !== null && !is_bool($manual)) || ($active !== null && !is_bool($active))) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        $program = $this->programs->findByBusinessId($id) ?? new LoyaltyProgram($id);
        if ($manual !== null) {
            $program->setManuallyEnabled($manual);
        }
        if ($active === true) {
            $status = $this->availability->check($id, $program);
            if (!$status->canActivate()) {
                return new JsonResponse([
                    'error'  => 'cannot_activate',
                    'reason' => $status->isEnabled() ? 'no_rewards' : 'not_enabled',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        if ($active !== null) {
            $program->setActive($active);
        }
        $this->programs->save($program);

        return new JsonResponse($this->serialize($id, $program));
    }

    /** @return array<string, mixed> */
    private function serialize(string $id, ?LoyaltyProgram $program): array
    {
        $rewards = [];
        foreach (LoyaltyCard::REWARD_STAGES as $stage) {
            $label = $program?->rewardFor($stage);
            $rewards[(string) $stage] = $label === null
                ? null
                : ['label' => $label, 'description' => $program->rewardDescription($stage)];
        }

        $stats = $this->events->statsForBusiness($id);

        return $this->availability->check($id, $program)->toArray() + [
            'manually_enabled_at' => $program?->getManuallyEnabledAt()?->format(\DATE_ATOM),
            'activated_at'        => $program?->getActivatedAt()?->format(\DATE_ATOM),
            'max_stamps'          => LoyaltyCard::MAX_STAMPS,
            'rewards'             => $rewards,
            'stats'               => [
                'customers'        => $stats['customers'],
                'stamps'           => $stats['stamps'],
                'redemptions'      => $stats['redemptions'],
                'last_activity_at' => $stats['last_activity_at']?->format(\DATE_ATOM),
            ],
        ];
    }
}
