<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\Application\SubscriptionCanceller;
use App\Billing\Domain\BusinessSubscription;
use App\Billing\Domain\BusinessSubscriptionRepository;
use App\Billing\Domain\SubscriptionStatus;
use App\Billing\Infrastructure\Stripe\StripeClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Lo que decide si se sigue cobrando por un negocio borrado.
 *
 * Sin clave de Stripe, el servicio no llama a la pasarela; eso es justo lo que
 * pasa en los tests, y lo que se comprueba aquí es que en ese caso no revienta
 * nada y que la parte que sí depende de nosotros —marcar las filas— ocurre
 * igual.
 */
final class SubscriptionCancellerTest extends TestCase
{
    public function testDoesNothingWhenTheBusinessHasNoSubscription(): void
    {
        $canceller = $this->canceller(new InMemorySubscriptions([]));

        self::assertSame('none', $canceller->cancelNow('negocio-1'));
    }

    public function testPurgeCancelsEveryLiveSubscription(): void
    {
        // Impago y pendiente de pago siguen vivas en Stripe: si sólo se cancela
        // la «activa», ésas se quedan intentando cobrar a un negocio borrado.
        $subscriptions = new InMemorySubscriptions([
            $this->subscription('a', SubscriptionStatus::Active),
            $this->subscription('b', SubscriptionStatus::PastDue),
            $this->subscription('c', SubscriptionStatus::Cancelled),
        ]);

        $this->canceller($subscriptions)->cancelNow('negocio-1');

        self::assertSame(
            [SubscriptionStatus::Cancelled, SubscriptionStatus::Cancelled, SubscriptionStatus::Cancelled],
            array_map(static fn (BusinessSubscription $s) => $s->getStatus(), $subscriptions->all),
        );
        // La que ya estaba cancelada no se vuelve a tocar.
        self::assertSame(['a', 'b'], $subscriptions->saved);
    }

    private function canceller(BusinessSubscriptionRepository $subscriptions): SubscriptionCanceller
    {
        // Sin clave: `isConfigured()` es false y no se llama a la pasarela.
        return new SubscriptionCanceller($subscriptions, new StripeClientFactory(''), new NullLogger());
    }

    private function subscription(string $id, SubscriptionStatus $status): BusinessSubscription
    {
        $subscription = new BusinessSubscription($id, 'negocio-1', 'plan-1', $status);

        if ($status === SubscriptionStatus::Cancelled) {
            $subscription->cancel();
        }

        return $subscription;
    }
}

/** @internal doble del repositorio, para no necesitar base de datos */
final class InMemorySubscriptions implements BusinessSubscriptionRepository
{
    /** @var string[] ids que se han guardado */
    public array $saved = [];

    /** @param BusinessSubscription[] $all */
    public function __construct(public array $all) {}

    public function findById(string $id): ?BusinessSubscription
    {
        return null;
    }

    public function findActiveByBusinessId(string $businessId): ?BusinessSubscription
    {
        foreach ($this->all as $subscription) {
            if (in_array($subscription->getStatus(), [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)) {
                return $subscription;
            }
        }

        return null;
    }

    public function findByStripeSubscriptionId(string $stripeSubscriptionId): ?BusinessSubscription
    {
        return null;
    }

    public function findByStripePaymentLinkId(string $stripePaymentLinkId): ?BusinessSubscription
    {
        return null;
    }

    public function findByBusinessId(string $businessId): array
    {
        return $this->all;
    }

    public function save(BusinessSubscription $subscription): void
    {
        $this->saved[] = $subscription->getId();
    }
}
