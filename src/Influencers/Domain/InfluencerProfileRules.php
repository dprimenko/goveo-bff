<?php

declare(strict_types=1);

namespace App\Influencers\Domain;

/**
 * Qué vale como nombre, usuario, bio y redes de un creador. Lo comparten el
 * panel, el alta pública y la edición desde la app: si cada uno validara a su
 * manera, un usuario aceptado en un sitio se rechazaría en otro.
 */
final class InfluencerProfileRules
{
    /** Minúsculas, números, punto, guion y guion bajo; de 3 a 40. */
    public const USERNAME = '/^[a-z0-9][a-z0-9._-]{2,39}$/';
    public const NAME_MAX = 120;
    public const BIO_MAX  = 1000;

    /** Las redes que se piden en el alta, guardadas en `meta` por su nombre. */
    public const SOCIALS = ['instagram', 'tiktok'];

    public static function validName(string $name): bool
    {
        return $name !== '' && mb_strlen($name) <= self::NAME_MAX;
    }

    public static function validUsername(string $username): bool
    {
        return (bool) preg_match(self::USERNAME, $username);
    }

    public static function bio(mixed $bio): ?string
    {
        $bio = trim((string) $bio);

        return $bio === '' ? null : mb_substr($bio, 0, self::BIO_MAX);
    }

    /**
     * El usuario de una red, venga como venga: `@goveo`, `goveo` o el enlace
     * entero (`https://www.instagram.com/goveo/`). `null` si está vacío, y
     * `false` si no se parece a un usuario.
     */
    public static function socialHandle(mixed $value): string|false|null
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        // Del enlace, el primer tramo de la ruta (TikTok lo lleva con @).
        if (preg_match('~^(?:https?://)?(?:www\.)?[a-z]+\.com/@?([^/?#]+)~i', $value, $m)) {
            $value = $m[1];
        }
        $value = ltrim($value, '@');

        return preg_match('/^[A-Za-z0-9._]{1,30}$/', $value) ? $value : false;
    }
}
