<?php

declare(strict_types=1);

namespace App\Loyalty\Domain;

enum LoyaltyTokenKind: string
{
    /** Suma un sello. */
    case Stamp = 'stamp';

    /** Canjea el premio de un sello concreto y deja la tarjeta a cero. */
    case Redeem = 'redeem';
}
