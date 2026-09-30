<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use App\Billing\Application\PlanOffer;
use App\Billing\Domain\BillingPlanRepository;
use App\Business\Domain\BusinessRepository;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Cambiar la tarifa de un negocio y pasárselo a su dueño, desde la ficha del
 * panel. Lo mismo que `goveo:billing:offer-plan`; ver `PlanOffer`.
 *
 * POST /api/admin/businesses/{id}/plan-offer
 * Body: {"plan": "platinum-anual", "email": "cliente@…", "first_name"?, "last_name"?, "invited"?: true}
 *
 * Devuelve el enlace de pago de Stripe para pasárselo al cliente (nulo si la
 * tarifa es gratuita o de invitación), y si la cuenta es nueva y si ya lo
 * gestionaba. `invited`: la tarifa se da sin cobro —pagó por fuera— y se
 * activa ya.
 */
#[IsGranted('ROLE_BUSINESS_EDIT')]
class OfferBusinessPlanController
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly BillingPlanRepository $plans,
        private readonly PlanOffer $offer,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/api/admin/businesses/{id}/plan-offer', name: 'admin_business_plan_offer', methods: ['POST'])]
    public function __invoke(string $id, Request $request): Response
    {
        $business = $this->businesses->findById($id);
        if ($business === null || $business->isDeleted()) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $planRef = is_array($payload) ? trim((string) ($payload['plan'] ?? '')) : '';
        $email   = is_array($payload) ? strtolower(trim((string) ($payload['email'] ?? ''))) : '';

        $plan = $planRef === '' ? null : ($this->plans->findByCode($planRef) ?? $this->plans->findById($planRef));
        if ($plan === null || !$plan->isActive()) {
            return new JsonResponse(['error' => 'plan_not_available'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'invalid_email'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->offer->offer(
                $business,
                $plan,
                $email,
                trim((string) ($payload['first_name'] ?? '')),
                trim((string) ($payload['last_name'] ?? '')),
                ($payload['invited'] ?? false) === true,
            );
        } catch (ApiErrorException $e) {
            $this->logger->error('Stripe no pudo crear el enlace de pago: {message}', ['message' => $e->getMessage()]);

            return new JsonResponse(['error' => 'stripe_unavailable'], Response::HTTP_BAD_GATEWAY);
        } catch (\RuntimeException $e) {
            // La tarifa de pago sin precio en Stripe (falta `goveo:stripe:sync`).
            return new JsonResponse(['error' => 'plan_not_in_stripe', 'detail' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'payment_url'     => $result['payment_url'],
            'invited'         => $result['subscription']->isInvitation(),
            'account_created' => $result['account_created'],
            'needs_password'  => $result['needs_password'],
            'manager_added'   => $result['manager_added'],
            // Se repitió con otro correo antes de que pagara: al anterior se le
            // ha quitado el acceso.
            'previous_removed' => $result['removed_user'] !== null,
        ], Response::HTTP_CREATED);
    }
}
