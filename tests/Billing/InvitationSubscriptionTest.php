<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\Domain\BusinessSubscription;
use PHPUnit\Framework\TestCase;

/**
 * Una tarifa de invitación: el cliente pagó por fuera (o se le regala) y la
 * tarifa tiene que valer ya, sin pasar por Stripe.
 */
final class InvitationSubscriptionTest extends TestCase
{
    public function testIsActiveAtOnceWithoutAnythingInStripe(): void
    {
        $subscription = BusinessSubscription::invitation('sub-1', 'negocio-1', 'plan-top3');

        self::assertTrue($subscription->isActive());
        self::assertFalse($subscription->isPendingPayment());
        self::assertTrue($subscription->isInvitation());
        self::assertNull($subscription->getStripeSubscriptionId());
        self::assertNull($subscription->getPaymentUrl());
        // Como la gratuita: no hay periodo que vencer, así que nada lo corta.
        self::assertNull($subscription->getCurrentPeriodEnd());
    }

    public function testAnOrdinarySubscriptionIsNotAnInvitation(): void
    {
        $pending = BusinessSubscription::pendingPayment('sub-2', 'negocio-1', 'plan-top3', 18000, 'price_1', 'plink_1', 'https://buy.stripe.com/x');

        self::assertFalse($pending->isInvitation());
    }
}
