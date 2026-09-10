<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\BusinessSubscriptionRepository;
use App\Billing\Domain\SubscriptionStatus;
use App\Billing\Infrastructure\Stripe\StripeClientFactory;
use Psr\Log\LoggerInterface;

/**
 * Deja de cobrar un negocio que se borra.
 *
 * **Sólo lo llama el borrado definitivo.** Archivar, que es reversible, no toca
 * la suscripción: cancelar en Stripe no se deshace del todo —una cancelada no se
 * descancela— y un archivado por error no puede acabar costando la suscripción
 * de alguien. Dejar de cobrar a un negocio archivado se hace a mano.
 *
 * **Un fallo de Stripe no impide borrar.** Se registra y se devuelve `failed`
 * para poder decirlo en el panel: dejar el negocio a medio borrar porque la
 * pasarela no contesta es peor que borrarlo y avisar.
 */
final class SubscriptionCanceller
{
    public function __construct(
        private readonly BusinessSubscriptionRepository $subscriptions,
        private readonly StripeClientFactory $stripe,
        private readonly LoggerInterface $logger,
    ) {}

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

}
