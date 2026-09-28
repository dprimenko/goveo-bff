<?php

declare(strict_types=1);

namespace App\Business\Application;

use App\Billing\Application\PendingPaymentMailer;

use App\Account\Application\WelcomeMailer;
use App\Backoffice\Application\ReviewQueueNotifier;
use App\Account\Application\AccountProvisioner;
use App\Billing\Domain\BillingPlan;
use App\Billing\Domain\BusinessSubscription;
use App\Billing\Domain\BusinessSubscriptionRepository;
use App\Billing\Domain\SubscriptionStatus;
use App\Billing\Domain\BillingProductRepository;
use App\Billing\Application\PaymentLinkCreator;
use App\Business\Domain\Business;
use App\Business\Domain\BusinessManager;
use App\Business\Domain\BusinessManagerRepository;
use App\Business\Domain\BusinessRepository;
use App\Business\Domain\CityLookup;
use App\Users\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Alta de negocio desde el formulario público de la landing.
 *
 * El orden importa y es el contrario al de la app: aquí **el negocio nace antes
 * de pagar**. Se crea sin validar, con su dueño ya asignado, y el enlace de
 * Stripe lleva su id en `metadata` para que el cobro se pueda atribuir.
 *
 * La cuenta se crea en Keycloak **sin contraseña**: el dueño la define después,
 * desde el correo de bienvenida. Si el email ya existe se reutiliza esa cuenta,
 * porque dar de alta un segundo negocio con el mismo correo es normal.
 *
 * Pagar no valida nada: `verified_at` sigue nulo hasta que alguien de Goveo lo
 * revise a mano.
 */
final class PublicBusinessRegistration
{
    public function __construct(
        private readonly AccountProvisioner $accounts,
        private readonly BusinessRepository $businesses,
        private readonly BusinessManagerRepository $managers,
        private readonly BusinessSubscriptionRepository $subscriptions,
        private readonly BillingProductRepository $products,
        private readonly BusinessSlugger $slugger,
        private readonly CityLookup $cities,
        private readonly PaymentLinkCreator $paymentLinks,
        private readonly WelcomeMailer $welcome,
        private readonly ReviewQueueNotifier $reviewQueue,
        private readonly PendingPaymentMailer $pendingPayment,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @param array<string,mixed> $data
     *
     * @return array{business: Business, subscription: BusinessSubscription, payment_url: ?string, account_created: bool}
     */
    public function register(array $data, BillingPlan $plan, ?User $owner = null): array
    {
        $email = strtolower(trim((string) $data['email']));

        // Con `$owner` viene alguien que ya ha iniciado sesión: su cuenta ya
        // existe y su identidad la ha probado el token, no el formulario.
        if ($owner !== null) {
            return $this->createFor($owner, $data, $plan, accountCreated: false);
        }

        $account = $this->accounts->forEmail(
            $email,
            (string) ($data['owner_first_name'] ?? ''),
            (string) ($data['owner_last_name'] ?? ''),
        );

        return $this->createFor($account['user'], $data, $plan, $account['created']);
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array{business: Business, subscription: BusinessSubscription, payment_url: ?string, account_created: bool}
     */
    private function createFor(
        User $user,
        array $data,
        BillingPlan $plan,
        bool $accountCreated,
    ): array {
        $businessId = Uuid::v4()->toRfc4122();
        $business   = new Business(
            id:         $businessId,
            slug:       $this->slugger->forName((string) $data['name']),
            categoryId: (string) $data['category_id'],
            creatorId:  $user->getId(),
            name:       trim((string) $data['name']),
            avatar:     $this->str($data['avatar'] ?? null),
            mainImage:  $this->str($data['main_image'] ?? null),
            meta:       $this->buildMeta($data),
        );
        $latitude  = (float) $data['address']['lat'];
        $longitude = (float) $data['address']['lng'];

        $business->setLocation($latitude, $longitude);
        // La ciudad, desde el minuto uno: un alta nueva tiene que salir en el
        // filtro del panel sin esperar al siguiente relleno. `cityAt` no lanza,
        // así que Google caído no impide dar de alta un negocio.
        $business->setCity($this->cities->cityAt($latitude, $longitude));

        // El enlace de pago se crea antes de la transacción: si Stripe falla no
        // queremos un negocio a medias, y si falla la base no queda un enlace
        // cobrando algo que no existe.
        $checkout = $this->paymentLinks->create($businessId, (string) $data['name'], $plan, $user->getEmail());

        // Una tarifa gratuita no espera ningún pago: nace activa. Dejarla en
        // `pending_payment` la haría parecer un alta abandonada, y el comando de
        // purga se la llevaría a los siete días.
        $subscription = $checkout['url'] === null
            ? new BusinessSubscription(
                id:                 Uuid::v4()->toRfc4122(),
                businessId:         $businessId,
                billingPlanId:      $plan->getId(),
                status:             SubscriptionStatus::Active,
                currentPeriodStart: new \DateTimeImmutable(),
                // Gratis no vence.
                currentPeriodEnd:   null,
            )
            : BusinessSubscription::pendingPayment(
                id:                  Uuid::v4()->toRfc4122(),
                businessId:          $businessId,
                billingPlanId:       $plan->getId(),
                amountCents:         $plan->getAmountCents(),
                stripePriceId:       $checkout['price_id'],
                stripePaymentLinkId: $checkout['link_id'],
                paymentUrl:          $checkout['url'],
            );

        $this->em->beginTransaction();
        try {
            $this->businesses->save($business);
            $this->managers->save(new BusinessManager($user->getId(), $businessId));
            $this->subscriptions->save($subscription);
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }

        // Con tarifa gratuita no hay pago que esperar, así que la bienvenida
        // sale ya. En las de pago la manda el webhook al confirmarse el cobro:
        // quien abandona en la pasarela no debe recibir un «bienvenido».
        if ($checkout['url'] === null) {
            // El aviso interno sale con la bienvenida y no al crear la ficha:
            // quien abandona en la pasarela no deja nada que revisar.
            $this->reviewQueue->businessPendingReview($business);

            $this->welcome->send(
                $business,
                $user->getId(),
                // El del usuario, no el del formulario: con sesión iniciada, la
                // identidad la fija el token.
                (string) $user->getEmail(),
                trim(sprintf('%s %s', $data['owner_first_name'] ?? '', $data['owner_last_name'] ?? '')) ?: null,
            );
        } else {
            // Con pago por delante, lo que se manda es el enlace para
            // terminarlo. El de Stripe vivía sólo en la pantalla que se acababa
            // de abrir: cerrar la pestaña dejaba el negocio creado y el cobro
            // sin forma de retomarse. Ver PendingPaymentMailer.
            $this->pendingPayment->send(
                (string) $user->getEmail(),
                (string) $business->getName(),
                $business->getId(),
                $plan->getAmountCents(),
            );
        }

        return [
            'business'        => $business,
            'subscription'    => $subscription,
            'payment_url'     => $checkout['url'],
            'account_created' => $accountCreated,
        ];
    }

    /** Mismas claves que usan los negocios importados: el perfil lee de ahí. */
    private function buildMeta(array $data): array
    {
        return array_filter([
            'address'      => $this->str($data['address']['formatted'] ?? null),
            'public_phone' => $this->str($data['phone'] ?? null),
            'website_url'  => $this->str($data['website'] ?? null),
            'booking_url'  => $this->str($data['booking_url'] ?? null),
            'billing'      => array_filter(
                (array) ($data['billing'] ?? []),
                static fn ($v) => $v !== null && $v !== '',
            ),
        ], static fn ($v) => $v !== null && $v !== []);
    }

    private function str(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
