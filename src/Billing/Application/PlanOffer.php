<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Account\Application\AccountProvisioner;
use App\Account\Application\WelcomeMailer;
use App\Auth\Infrastructure\Service\KeycloakService;
use App\Billing\Domain\BillingPlan;
use App\Billing\Domain\BusinessSubscription;
use App\Billing\Domain\BusinessSubscriptionRepository;
use App\Billing\Domain\SubscriptionStatus;
use App\Business\Domain\Business;
use App\Business\Domain\BusinessManager;
use App\Business\Domain\BusinessManagerRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Pasarle a un negocio que ya existe una tarifa, y a su dueño la cuenta.
 *
 * El caso: el equipo da de alta un negocio con una cuenta propia y la tarifa
 * gratuita, le prepara la ficha al cliente y, cuando la quiere, le cambia la
 * tarifa y se la pasa. Es lo mismo que el alta pública, pero sobre un negocio
 * hecho:
 *
 * 1. **El correo del cliente pasa a gestionar el negocio.** Si no tiene cuenta,
 *    se le crea sin contraseña (`AccountProvisioner`).
 * 2. **La tarifa.** De pago: una suscripción nueva pendiente con su enlace de
 *    Stripe, el mismo del alta (`PaymentLinkCreator`). **La que tuviera sigue
 *    viva hasta que pague** —la gratuita no se le quita al negocio por haberle
 *    mandado un enlace—; al pagar, el webhook la cierra. Si había otro enlace
 *    sin pagar, se anula: el que vale es el último. Gratuita: se activa ya.
 * 3. **Si se repite con otro correo antes de que pague** (el primero estaba mal),
 *    al del ofrecimiento anterior se le quita el acceso —salvo que sea quien
 *    creó el negocio—. Lo que ya le llegó por correo no se puede deshacer, y si
 *    se le creó cuenta, se queda sin nada que gestionar.
 * 4. **Los correos del alta**, al cliente: la bienvenida (con «crea tu
 *    contraseña» si la cuenta es nueva, o sin ella si ya tenía) y, si hay que
 *    pagar, el de «termina el alta» con el enlace.
 */
final class PlanOffer
{
    public function __construct(
        private readonly AccountProvisioner $accounts,
        private readonly BusinessManagerRepository $managers,
        private readonly BusinessSubscriptionRepository $subscriptions,
        private readonly PaymentLinkCreator $paymentLinks,
        private readonly WelcomeMailer $welcome,
        private readonly PendingPaymentMailer $pendingPayment,
        private readonly KeycloakService $keycloak,
    ) {}

    /**
     * @return array{subscription: BusinessSubscription, payment_url: ?string, account_created: bool, manager_added: bool, removed_user: ?string, needs_password: bool}
     *
     * @throws \RuntimeException si la tarifa de pago no está en Stripe
     * @throws \Stripe\Exception\ApiErrorException si Stripe no responde
     */
    public function offer(Business $business, BillingPlan $plan, string $email, string $firstName = '', string $lastName = ''): array
    {
        $businessId = $business->getId();
        $name       = $business->getName() ?? $business->getSlug();

        // El enlace, lo primero: si Stripe falla no se ha tocado nada.
        $checkout = $this->paymentLinks->create($businessId, $name, $plan, $email);
        $free     = $checkout['url'] === null;

        $account = $this->accounts->forEmail($email, $firstName, $lastName);
        $user    = $account['user'];

        $manager      = $this->managers->findByUserAndBusiness($user->getId(), $businessId);
        $managerAdded = $manager === null || $manager->isDeleted();
        if ($manager === null) {
            $this->managers->save(new BusinessManager($user->getId(), $businessId));
        } elseif ($manager->isDeleted()) {
            $this->managers->save($manager->restore());
        }

        // Los enlaces anteriores sin pagar dejan de valer: el que cuenta es este.
        // Con una tarifa gratuita, además, se cierra la que hubiera activa.
        $removed = null;
        foreach ($this->subscriptions->findByBusinessId($businessId) as $previous) {
            $stale = $previous->isPendingPayment()
                || ($free && $previous->isActive() && $previous->getStripeSubscriptionId() === null);
            if (!$stale) {
                continue;
            }

            // Ofrecida antes a otro correo y sin pagar: era un error, y ese
            // correo no tiene por qué seguir gestionando el negocio.
            $wrong = $previous->isPendingPayment() ? $previous->getOfferedUserId() : null;
            if ($wrong !== null && $wrong !== $user->getId() && $wrong !== $business->getCreatorId()) {
                // Se borra la fila y no se marca: quien decide si alguien
                // gestiona un negocio (`ManagedBusinessFinder`, `/me`) no mira
                // `deleted_at`, y marcada seguiría teniendo acceso.
                $link = $this->managers->findByUserAndBusiness($wrong, $businessId);
                if ($link !== null) {
                    $this->managers->delete($link);
                    $removed = $wrong;
                }
            }

            $previous->cancel();
            $this->subscriptions->save($previous);
        }

        $subscription = $free
            ? new BusinessSubscription(
                id: Uuid::v4()->toRfc4122(),
                businessId: $businessId,
                billingPlanId: $plan->getId(),
                status: SubscriptionStatus::Active,
                currentPeriodStart: new \DateTimeImmutable(),
                currentPeriodEnd: null,
            )
            : BusinessSubscription::pendingPayment(
                Uuid::v4()->toRfc4122(),
                $businessId,
                $plan->getId(),
                $plan->getAmountCents(),
                $checkout['price_id'],
                $checkout['link_id'],
                $checkout['url'],
            );
        $subscription->offeredTo($user->getId());
        $this->subscriptions->save($subscription);

        $email = (string) $user->getEmail();
        // Lo mismo que mira la bienvenida para elegir correo: una cuenta creada
        // en un ofrecimiento anterior tampoco tiene contraseña todavía.
        $needsPassword = $this->keycloak->hasPendingPasswordSetup($email);
        $this->welcome->send($business, $user->getId(), $email, $user->getName());
        if (!$free) {
            $this->pendingPayment->send($email, $name, $businessId, $plan->getAmountCents());
        }

        return [
            'subscription'    => $subscription,
            'payment_url'     => $checkout['url'],
            'account_created' => $account['created'],
            'manager_added'   => $managerAdded,
            'removed_user'    => $removed,
            'needs_password'  => $needsPassword,
        ];
    }
}
