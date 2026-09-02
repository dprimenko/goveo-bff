<?php

declare(strict_types=1);

namespace App\Follows\Infrastructure\Service;

use App\Follows\Domain\FollowRepository;
use App\Follows\Domain\FollowTarget;

/**
 * Nº de seguidores que se publica en la API: `meta.followers` **más** los
 * seguidores reales de `user_follows`.
 *
 * `meta.followers` no son seguidores: es un número de relleno que se pone a
 * mano para que las fichas no salgan vacías mientras la app arranca. El día que
 * se quite del `meta`, esto devuelve sólo el recuento real sin tocar nada.
 *
 * Antes ese número **mandaba** sobre el recuento, y eso congelaba el contador:
 * casi todas las fichas lo traen, así que seguir a una guardaba el follow pero
 * no movía la cifra. En la app se veía aún peor —sumaba uno al pulsar y el
 * servidor lo devolvía al valor anterior—, el clásico «sube y baja».
 *
 * Se suma en vez de usar `max()` porque con el máximo el relleno seguiría
 * tapando a los seguidores de verdad: una ficha con 94 de relleno no movería el
 * número hasta tener 95 reales.
 */
final class FollowerCounter
{
    public function __construct(
        private readonly FollowRepository $follows,
    ) {}

    public function resolve(FollowTarget $type, string $targetId, ?array $meta): int
    {
        $inherited = $meta['followers'] ?? null;
        $real      = $this->follows->countFollowers($type, $targetId);

        if (!is_numeric($inherited)) {
            return $real;
        }

        // Nunca por debajo de lo heredado: un valor negativo en `meta` no puede
        // restar seguidores de verdad.
        return max(0, (int) $inherited) + $real;
    }
}
