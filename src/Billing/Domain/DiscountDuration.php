<?php

declare(strict_types=1);

namespace App\Billing\Domain;

/**
 * How long a discount applies. Mirrors Stripe's Coupon `duration`.
 *
 * Replaces the legacy `before` flag, which encoded "the percentage only applies
 * to the first period" as a boolean and had to be re-interpreted in every view.
 */
enum DiscountDuration: string
{
    /** First invoice only. */
    case Once = 'once';

    /** `durationInMonths` months, then full price. */
    case Repeating = 'repeating';

    /** For the whole life of the subscription. */
    case Forever = 'forever';
}
