<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\BillingMode;
use App\Billing\Domain\BillingPlan;
use App\Billing\Domain\BusinessSubscription;
use App\Billing\Domain\BusinessSubscriptionRepository;
use App\Billing\Domain\Discount;
use App\Billing\Domain\PromoCode;
use App\Billing\Domain\SubscriptionStatus;
use App\Billing\Infrastructure\Stripe\StripeClientFactory;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Uid\Uuid;

/**
 * Da de alta la suscripción de un negocio recién creado.
 *
 * Tres caminos, y el que se toma lo decide el plan y el código, nunca quien
 * llama:
 *
 *  - **Gratis** o **facturado fuera de la app**: no se toca Stripe. Se guarda la
 *    suscripción activa igualmente, porque es lo que dice a qué tarifa está el
 *    negocio.
 *  - **De pago**: cliente + suscripción en Stripe. Hace falta método de pago.
 *  - **Trial**: igual, con `trial_period_days`. La tarjeta se pide **también**
 *    aquí: sin ella Stripe no puede cobrar al acabar la prueba y la suscripción
 *    se queda encallada en `missing_payment_method`.
 */
final class SubscriptionCreator
{
    public function __construct(
        private readonly StripeClientFactory $stripeFactory,
        private readonly BusinessSubscriptionRepository $subscriptions,
    ) {}

    /**
     * @throws MissingPaymentMethod si el plan cobra y no llega tarjeta
     * @throws ApiErrorException    si Stripe rechaza la operación
     */
    public function create(
        string $businessId,
        BillingPlan $plan,
        PromoCode $code,
        ?Discount $discount,
        ?string $paymentMethodId,
        array $billingDetails,
    ): BusinessSubscription {
        $chargesInApp = $plan->getMode()->requiresPayment() && !$code->isBilledExternally();

        if (!$chargesInApp) {
            return $this->persistWithoutStripe($businessId, $plan, $code);
        }

        if ($paymentMethodId === null || $paymentMethodId === '') {
            throw new MissingPaymentMethod();
        }

        $stripe = $this->stripeFactory->create();

        $customer = $stripe->customers->create([
            'payment_method'   => $paymentMethodId,
            'email'            => $billingDetails['email'] ?? null,
            'name'             => $billingDetails['name'] ?? null,
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
            'metadata'         => ['goveo_business_id' => $businessId],
        ]);

        $payload = [
            'customer' => $customer->id,
            'items'    => [['price' => $plan->getStripePriceId()]],
            'metadata' => [
                'goveo_business_id' => $businessId,
                'goveo_promo_code'  => $code->getCode(),
            ],
        ];

        if ($plan->getMode() === BillingMode::TrialThenPaid) {
            $payload['trial_period_days'] = $plan->getTrialDays();
        }

        // El descuento se manda como el Coupon de Stripe, no restando importes a
        // mano: así lo que se cobra sale de la misma fuente que lo que se enseñó.
        if ($discount?->getStripeCouponId() !== null) {
            $payload['discounts'] = [['coupon' => $discount->getStripeCouponId()]];
        }

        $stripeSubscription = $stripe->subscriptions->create($payload);

        $subscription = new BusinessSubscription(
            id:                   Uuid::v4()->toRfc4122(),
            businessId:           $businessId,
            billingPlanId:        $plan->getId(),
            status:               $this->mapStatus($stripeSubscription->status),
            currentPeriodStart:   $this->at($stripeSubscription, 'current_period_start'),
            currentPeriodEnd:     $this->at($stripeSubscription, 'current_period_end'),
            stripeSubscriptionId: $stripeSubscription->id,
        );

        $this->subscriptions->save($subscription);

        return $subscription;
    }

    private function persistWithoutStripe(
        string $businessId,
        BillingPlan $plan,
        PromoCode $code,
    ): BusinessSubscription {
        $now = new \DateTimeImmutable();

        $subscription = new BusinessSubscription(
            id:                 Uuid::v4()->toRfc4122(),
            businessId:         $businessId,
            billingPlanId:      $plan->getId(),
            status:             SubscriptionStatus::Active,
            currentPeriodStart: $now,
            currentPeriodEnd:   $this->addPeriod($now, $plan),
            promoCodeId:        $code->getId(),
        );

        $this->subscriptions->save($subscription);

        return $subscription;
    }

    private function addPeriod(\DateTimeImmutable $from, BillingPlan $plan): \DateTimeImmutable
    {
        return $from->modify(sprintf(
            '+%d %s',
            $plan->getIntervalCount(),
            $plan->getInterval()->value,
        ));
    }

    private function mapStatus(string $stripeStatus): SubscriptionStatus
    {
        return match ($stripeStatus) {
            'active'             => SubscriptionStatus::Active,
            'trialing'           => SubscriptionStatus::Trialing,
            'past_due', 'unpaid' => SubscriptionStatus::PastDue,
            'canceled'           => SubscriptionStatus::Cancelled,
            'paused'             => SubscriptionStatus::Paused,
            default              => SubscriptionStatus::Incomplete,
        };
    }

    /**
     * Stripe movió los periodos al item de la suscripción en versiones recientes;
     * se leen de donde estén y, si no están, se cae a ahora para no reventar el
     * alta por un campo de presentación.
     */
    private function at(object $subscription, string $field): \DateTimeImmutable
    {
        $value = $subscription->$field
            ?? ($subscription->items->data[0]->$field ?? null);

        return $value !== null
            ? (new \DateTimeImmutable())->setTimestamp((int) $value)
            : new \DateTimeImmutable();
    }
}
