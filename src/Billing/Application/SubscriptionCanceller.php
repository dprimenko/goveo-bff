<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\BusinessSubscriptionRepository;
use App\Billing\Domain\SubscriptionStatus;
use App\Billing\Infrastructure\Stripe\StripeClientFactory;
use Psr\Log\LoggerInterface;

/**
 * Deja de cobrar cuando un negocio deja de estar.
 *
 * Archivar y borrar son cosas distintas y aquí se notan: **archivar se puede
 * deshacer y borrar no**, así que no pueden cancelar igual.
 *
 * - Al archivar se programa el corte **para el final del periodo ya pagado**
 *   (`cancel_at_period_end`). Nadie paga un mes más por algo que ya no se ve, y
 *   si el negocio vuelve antes de esa fecha basta con quitar la marca: no se ha
 *   perdido nada. Cancelar en el acto sería tirar los días pagados y, sobre
 *   todo, irreversible — una suscripción cancelada no se descancela.
 * - Al borrar del todo se corta ya, porque no hay vuelta.
 *
 * Lo que pasa en Stripe vuelve solo a la base por el webhook
 * (`customer.subscription.updated|deleted`), así que aquí no se toca el estado
 * salvo al borrar, donde la fila desaparece antes de que llegue el aviso.
 *
 * **Un fallo de Stripe no impide archivar ni borrar.** Se registra y se devuelve
 * `failed` para poder decirlo en el panel: dejar el negocio a medio archivar
 * porque la pasarela no contesta es peor que archivarlo y avisar.
 */
final class SubscriptionCanceller
{
    public function __construct(
        private readonly BusinessSubscriptionRepository $subscriptions,
        private readonly StripeClientFactory $stripe,
        private readonly LoggerInterface $logger,
    ) {}

    /** Programa el corte al final del periodo pagado. */
    public function scheduleCancellation(string $businessId): string
    {
        return $this->setCancelAtPeriodEnd($businessId, true, 'scheduled');
    }

    /** Deshace lo anterior, si aún estaba a tiempo. */
    public function resume(string $businessId): string
    {
        return $this->setCancelAtPeriodEnd($businessId, false, 'resumed');
    }

    /**
     * Corta ya, sin esperar al final del periodo, y **todas** las del negocio:
     * aquí no vale quedarse con la «activa», porque una en impago o a medio
     * pagar también sigue viva en Stripe y seguiría intentando cobrar.
     */
    public function cancelNow(string $businessId): string
    {
        $result = 'none';

        foreach ($this->subscriptions->findByBusinessId($businessId) as $subscription) {
            if ($subscription->getStatus() === SubscriptionStatus::Cancelled) {
                continue;
            }

            $stripeId = $subscription->getStripeSubscriptionId();

            // Las que llegaron del import no tienen id de Stripe: no hay nada
            // que parar allí, sólo la fila, que se va con el negocio.
            if ($stripeId === null || !$this->stripe->isConfigured()) {
                $subscription->cancel();
                $this->subscriptions->save($subscription);
                continue;
            }

            try {
                $this->stripe->create()->subscriptions->cancel($stripeId);
            } catch (\Throwable $e) {
                $this->logger->error('No se pudo cancelar la suscripción en Stripe', [
                    'business'     => $businessId,
                    'subscription' => $stripeId,
                    'message'      => $e->getMessage(),
                ]);

                // Se sigue con las demás, pero el resultado ya no es limpio.
                $result = 'failed';
                continue;
            }

            $subscription->cancel();
            $this->subscriptions->save($subscription);

            if ($result !== 'failed') {
                $result = 'cancelled';
            }
        }

        return $result;
    }

    private function setCancelAtPeriodEnd(string $businessId, bool $value, string $done): string
    {
        $subscription = $this->subscriptions->findActiveByBusinessId($businessId);

        if ($subscription === null) {
            return 'none';
        }

        $stripeId = $subscription->getStripeSubscriptionId();

        if ($stripeId === null || !$this->stripe->isConfigured()) {
            return 'none';
        }

        try {
            $this->stripe->create()->subscriptions->update($stripeId, [
                'cancel_at_period_end' => $value,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo cambiar la suscripción en Stripe', [
                'business'     => $businessId,
                'subscription' => $stripeId,
                'cancel_at_period_end' => $value,
                'message'      => $e->getMessage(),
            ]);

            return 'failed';
        }

        return $done;
    }
}
