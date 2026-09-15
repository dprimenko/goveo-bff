<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Controller;

use App\Billing\Domain\BusinessSubscriptionRepository;
use App\Billing\Domain\SubscriptionStatus;
use App\Business\Domain\BusinessRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /public/registration/{business}/payment
 *
 * En qué punto está el pago de un alta, y por dónde se termina si quedó a
 * medias.
 *
 * Existe porque **el pago se interrumpe**: se cierra la pestaña, falla la
 * tarjeta, o se deja para luego porque hay que pedirle los datos al gestor.
 * Hasta ahora eso dejaba el negocio creado y sin cobrar, sin ninguna forma de
 * retomarlo: el enlace de Stripe sólo existía en la pantalla que se acababa de
 * cerrar.
 *
 * **Público y direccionado por el id del negocio**, que es un UUID. Lo que se
 * devuelve es el nombre, el importe y el enlace de pago — y ese enlace ya es
 * público de por sí: cualquiera que lo tenga puede pagar, que es precisamente
 * lo que se quiere (lo normal es que pague la gestoría, no quien rellenó el
 * formulario). No se devuelve nada de la cuenta ni de la facturación.
 */
#[Route('/public/registration/{business}/payment', name: 'public_payment_status', methods: ['GET'])]
class PublicPaymentStatusController
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly BusinessSubscriptionRepository $subscriptions,
    ) {}

    public function __invoke(string $business): Response
    {
        $negocio = $this->businesses->findById($business);

        if ($negocio === null || $negocio->isDeleted()) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        // La más reciente: el repositorio las devuelve ordenadas, y un negocio
        // puede acumular varias si alguien reintentó el alta.
        $suscripcion = $this->subscriptions->findByBusinessId($business)[0] ?? null;

        if ($suscripcion === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $pendiente = $suscripcion->getStatus() === SubscriptionStatus::PendingPayment;

        return new JsonResponse([
            'business_name' => $negocio->getName(),
            'status'        => $pendiente ? 'pending' : 'settled',
            // Sólo si sigue pendiente: enseñar un botón de pagar a quien ya pagó
            // es la forma más rápida de que alguien pague dos veces.
            'payment_url'   => $pendiente ? $suscripcion->getPaymentUrl() : null,
            'amount_cents'  => $suscripcion->getAmountCents(),
        ]);
    }
}
