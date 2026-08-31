<?php

declare(strict_types=1);

namespace App\Follows\Infrastructure\Service;

use App\Follows\Domain\FollowRepository;
use App\Follows\Domain\FollowTarget;

/**
 * Nº de seguidores que se publica en la API.
 *
 * Por defecto es el recuento real de `user_follows`, pero si el negocio o el
 * influencer trae `meta.followers` ese valor **manda**. Sirve para arrastrar la
 * cifra heredada de Firestore y para poder inflar/fijar el número a mano en
 * casos puntuales, sin tocar los follows reales.
 */
final class FollowerCounter
{
    public function __construct(
        private readonly FollowRepository $follows,
    ) {}

    public function resolve(FollowTarget $type, string $targetId, ?array $meta): int
    {
        $override = $meta['followers'] ?? null;

        if (is_numeric($override)) {
            return (int) $override;
        }

        return $this->follows->countFollowers($type, $targetId);
    }
}
