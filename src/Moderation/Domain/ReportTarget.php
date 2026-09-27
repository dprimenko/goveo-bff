<?php

declare(strict_types=1);

namespace App\Moderation\Domain;

/**
 * Qué se puede denunciar: todo lo que publica alguien que no es quien mira.
 * El valor es el que viaja por la API y el que se guarda en
 * `content_reports.target_type`.
 */
enum ReportTarget: string
{
    case GeoStory   = 'geostory';
    case Business   = 'business';
    case Influencer = 'influencer';
    case Product    = 'product';

    public static function tryFromLoose(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom(strtolower(trim($value)));
    }
}
