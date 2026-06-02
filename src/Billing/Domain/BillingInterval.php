<?php

declare(strict_types=1);

namespace App\Billing\Domain;

enum BillingInterval: string
{
    case Month = 'month';
    case Year  = 'year';
}
