<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use App\Business\Domain\BillingDetails;
use App\Business\Domain\BusinessRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Corregir los datos de facturación de un negocio desde el panel.
 *
 * `PATCH /api/admin/businesses/{id}/billing`
 * `{company_name?, tax_id?, email?, phone?, address?}` — sólo lo que cambia;
 * vacío borra el campo. Devuelve los datos como quedan.
 *
 * Sólo el panel: el gestor no los cambia desde la app ni la web. Es lo que
 * se factura, y hasta ahora sólo se escribía en el alta.
 *
 * ⚠️ No toca Stripe. El cliente de Stripe guarda los suyos (los que puso al
 * pagar), y las facturas salen con aquéllos.
 */
#[IsGranted('ROLE_BUSINESS_EDIT')]
class BusinessBillingController
{
    public function __construct(
        private readonly BusinessRepository $businesses,
    ) {}

    #[Route('/api/admin/businesses/{id}/billing', name: 'admin_business_billing', methods: ['PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function __invoke(string $id, Request $request): Response
    {
        $business = $this->businesses->findById($id);
        if ($business === null || $business->isDeleted()) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        $meta   = $business->getMeta() ?? [];
        $result = BillingDetails::apply((array) ($meta['billing'] ?? []), $payload);

        if ($result['errors'] !== []) {
            return new JsonResponse(
                ['error' => 'validation_failed', 'fields' => $result['errors']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($result['billing'] === []) {
            unset($meta['billing']);
        } else {
            $meta['billing'] = $result['billing'];
        }
        $business->setMeta($meta);
        $this->businesses->save($business);

        return new JsonResponse(['billing' => array_merge(
            array_fill_keys(BillingDetails::FIELDS, null),
            $result['billing'],
        )]);
    }
}
