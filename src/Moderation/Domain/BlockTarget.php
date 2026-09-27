<?php

declare(strict_types=1);

namespace App\Moderation\Domain;

/**
 * A quién se puede bloquear. En Goveo sólo publican negocios e influencers
 * —los usuarios de a pie no suben nada ni comentan—, así que bloquear a «un
 * usuario abusivo» es bloquear a una de estas dos cuentas.
 */
enum BlockTarget: string
{
    case Business   = 'business';
    case Influencer = 'influencer';

    public static function tryFromLoose(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom(strtolower(trim($value)));
    }
}
