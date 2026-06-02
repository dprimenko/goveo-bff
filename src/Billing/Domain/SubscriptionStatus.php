<?php

declare(strict_types=1);

namespace App\Billing\Domain;

enum SubscriptionStatus: string
{
    case Active     = 'active';
    case Trialing   = 'trialing';
    case PastDue    = 'past_due';
    case Incomplete = 'incomplete';
    case Cancelled  = 'cancelled';
}
