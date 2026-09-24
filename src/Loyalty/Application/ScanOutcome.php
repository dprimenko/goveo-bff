<?php

declare(strict_types=1);

namespace App\Loyalty\Application;

/**
 * Lo que pasa al escanear un QR de la tarjeta.
 *
 * Sólo `Stamped`, `Redeemed` y `AlreadyApplied` dejan el QR gastado. En el
 * resto el QR queda como estaba, y el negocio lo puede usar con otro cliente.
 */
enum ScanOutcome: string
{
    case Stamped = 'stamped';
    case Redeemed = 'redeemed';

    /**
     * Este mismo usuario ya lo había usado. Pasa cuando el enlace llega dos
     * veces —el del escáner y el de Branch al abrir la app—, y no es un error:
     * el sello está puesto.
     */
    case AlreadyApplied = 'already_applied';

    /** Cinco sellos: tiene que canjear antes de seguir sumando. */
    case CardFull = 'card_full';

    /** Intenta canjear un premio al que no llega. */
    case NotEnoughStamps = 'not_enough_stamps';

    /** El negocio quitó ese premio después de generar el QR. */
    case RewardUnavailable = 'reward_unavailable';

    /** El negocio ya no ofrece tarjeta. */
    case Unavailable = 'unavailable';

    case Invalid = 'invalid';
    case Expired = 'expired';

    /** Otro usuario se adelantó. */
    case AlreadyUsed = 'already_used';

    public function isSuccess(): bool
    {
        return in_array($this, [self::Stamped, self::Redeemed, self::AlreadyApplied], true);
    }
}
