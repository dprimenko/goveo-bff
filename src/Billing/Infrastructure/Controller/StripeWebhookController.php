<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Controller;

use App\Billing\Domain\BillingPlanRepository;
use App\Billing\Domain\BusinessSubscriptionRepository;
use App\Billing\Domain\SubscriptionStatus;
use App\Billing\Infrastructure\Stripe\StripeClientFactory;
use App\Account\Application\WelcomeMailer;
use App\Business\Domain\BusinessRepository;
use App\Users\Domain\UserRepository;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Eventos de Stripe.
 *
 * Sustituye al trigger `event` de Firestore, que guardaba cada evento en una
 * colección y **recorría todas las tiendas** buscando la de esa suscripción.
 * Aquí se resuelve por `stripe_subscription_id`, que está indexado.
 *
 * Dos cosas distintas llegan por aquí:
 *  - Un negocio del alta web que acaba de pagar → se cierra su suscripción,
 *    que hasta entonces estaba en `pending_payment`.
 *  - Una suscripción que cambia de estado → se refleja en la del negocio.
 *
 * Pagar **no** valida el negocio: sigue con `verified_at` nulo hasta que alguien
 * de Goveo lo revise a mano.
 */
#[Route('/api/v1/webhooks/stripe', name: 'stripe_webhook', methods: ['POST'])]
class StripeWebhookController
{
    public function __construct(
        private readonly StripeClientFactory $stripeFactory,
        private readonly BusinessRepository $businesses,
        private readonly UserRepository $users,
        private readonly WelcomeMailer $welcome,
        private readonly BillingPlanRepository $plans,
        private readonly BusinessSubscriptionRepository $subscriptions,
        private readonly LoggerInterface $logger,
        private readonly string $webhookSecret,
    ) {}

    public function __invoke(Request $request): Response
    {
        if ($this->webhookSecret === '') {
            $this->logger->error('STRIPE_WEBHOOK_SECRET sin configurar: evento descartado.');

            return new JsonResponse(['error' => 'not_configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            // Se verifica la firma sobre el cuerpo crudo: sin esto, cualquiera
            // que conozca la URL podría regalarse una suscripción.
            $event = \Stripe\Webhook::constructEvent(
                $request->getContent(),
                $request->headers->get('stripe-signature', ''),
                $this->webhookSecret,
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            $this->logger->warning('Webhook de Stripe con firma inválida', ['message' => $e->getMessage()]);

            return new JsonResponse(['error' => 'invalid_signature'], Response::HTTP_BAD_REQUEST);
        }

        match ($event->type) {
            'checkout.session.completed'   => $this->onCheckoutCompleted($event->data->object),
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->onSubscriptionChanged($event->data->object),
            default => null,
        };

        // Siempre 200: un error nuestro no debe hacer que Stripe reintente en
        // bucle un evento que ya hemos visto.
        return new JsonResponse(['received' => true]);
    }

    private function onCheckoutCompleted(object $session): void
    {
        $paymentLinkId = is_string($session->payment_link ?? null)
            ? $session->payment_link
            : ($session->payment_link->id ?? null);

        $subscription = $paymentLinkId !== null
            ? $this->subscriptions->findByStripePaymentLinkId($paymentLinkId)
            : null;

        // Respaldo por metadata: si algún día se cobra sin Payment Link, el id
        // del negocio sigue viajando en el evento.
        if ($subscription === null && !empty($session->metadata->goveo_business_id)) {
            $subscription = $this->subscriptions->findActiveByBusinessId(
                (string) $session->metadata->goveo_business_id,
            );
        }

        if ($subscription === null) {
            $this->logger->info('Checkout completado sin suscripción asociada', [
                'session' => $session->id ?? null,
            ]);

            return;
        }

        // Idempotente: Stripe reintenta, y cerrar dos veces el mismo cobro
        // sobreescribiría el estado que ya haya traído otro evento posterior.
        if (!$subscription->isPendingPayment()) {
            return;
        }

        $subscription->confirmPayment(
            stripeSubscriptionId: is_string($session->subscription ?? null)
                ? $session->subscription
                : '',
            stripeCustomerId: is_string($session->customer ?? null) ? $session->customer : null,
            status: SubscriptionStatus::Active,
            periodStart: null,
            periodEnd: null,
        );
        $this->subscriptions->save($subscription);

        $this->logger->info('Cobro confirmado para el negocio', [
            'business'     => $subscription->getBusinessId(),
            'subscription' => $subscription->getStripeSubscriptionId(),
        ]);

        // Ahora sí toca la bienvenida: el negocio está pagado y su dueño
        // necesita entrar. Va dentro del `isPendingPayment` de arriba, así que
        // un reenvío del webhook no manda el correo dos veces.
        $business = $this->businesses->findById($subscription->getBusinessId());
        $owner    = $business !== null ? $this->users->findById($business->getCreatorId()) : null;

        if ($business !== null && $owner !== null && $owner->getEmail() !== null) {
            $this->welcome->send($business, $owner->getId(), $owner->getEmail(), $owner->getName());
        }
    }

    private function onSubscriptionChanged(object $subscription): void
    {
        $local = $this->subscriptions->findByStripeSubscriptionId((string) $subscription->id);
        if ($local === null) {
            return;
        }

        $local->syncFromStripe(
            $this->mapStatus((string) $subscription->status),
            $this->at($subscription, 'current_period_start'),
            $this->at($subscription, 'current_period_end'),
        );
        $this->subscriptions->save($local);
    }

    private function mapStatus(string $status): SubscriptionStatus
    {
        return match ($status) {
            'active'             => SubscriptionStatus::Active,
            'trialing'           => SubscriptionStatus::Trialing,
            'past_due', 'unpaid' => SubscriptionStatus::PastDue,
            'canceled'           => SubscriptionStatus::Cancelled,
            // Llega dentro de `customer.subscription.updated`: pausar cambia el
            // estado, así que no hace falta suscribirse además a
            // `customer.subscription.paused`.
            'paused'             => SubscriptionStatus::Paused,
            default              => SubscriptionStatus::Incomplete,
        };
    }

    private function at(object $subscription, string $field): \DateTimeImmutable
    {
        $value = $subscription->$field
            ?? ($subscription->items->data[0]->$field ?? null);

        return $value !== null
            ? (new \DateTimeImmutable())->setTimestamp((int) $value)
            : new \DateTimeImmutable();
    }
}
