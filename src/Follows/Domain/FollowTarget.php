<?php

declare(strict_types=1);

namespace App\Follows\Domain;

/**
 * Qué se puede seguir. El valor es el que viaja por la API y el que se guarda
 * en `user_follows.target_type`.
 */
enum FollowTarget: string
{
    case Business   = 'business';
    case Influencer = 'influencer';

    public static function tryFromLoose(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom(strtolower(trim($value)));
    }
}
