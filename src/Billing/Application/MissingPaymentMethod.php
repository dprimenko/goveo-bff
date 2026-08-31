<?php

declare(strict_types=1);

namespace App\Billing\Application;

/** El plan elegido cobra, pero el alta no trae método de pago. */
final class MissingPaymentMethod extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Esta tarifa requiere un método de pago.');
    }
}
