<?php

declare(strict_types=1);

namespace App\Billing\Domain;

enum SubscriptionStatus: string
{
    /**
     * Contratada desde el formulario web pero todavía sin pagar: existe el
     * enlace de Stripe y no se ha completado. El negocio ya existe, sin validar.
     */
    case PendingPayment = 'pending_payment';
    case Active     = 'active';
    case Trialing   = 'trialing';
    case PastDue    = 'past_due';
    case Incomplete = 'incomplete';
    case Cancelled  = 'cancelled';

    /**
     * Pausada en Stripe. Se distingue de `Incomplete` a propósito: aquélla es
     * un alta que nunca llegó a arrancar, ésta es una suscripción viva que
     * alguien ha parado y puede reanudarse.
     */
    case Paused     = 'paused';
}
