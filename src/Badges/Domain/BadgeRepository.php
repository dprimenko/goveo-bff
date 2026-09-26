<?php

declare(strict_types=1);

namespace App\Badges\Domain;

/**
 * Distintivos de un negocio (Ecológico, Terraza): lo que se enseña con un
 * emoji en la ficha y sirve de filtro en la búsqueda.
 *
 * No son categorías: un negocio está en una categoría y puede llevar varios
 * badges a la vez. Por eso «Ecológico» dejó de ser categoría — un restaurante
 * ecológico tenía que elegir entre ser restaurante o ser ecológico.
 *
 * Cada badge es `{id, slug, name, emoji}`, con `name` como clave de
 * traducción (`badge.<slug>`), igual que las categorías.
 */
interface BadgeRepository
{
    /** @return list<array{id: string, slug: string, name: string, emoji: string}> */
    public function all(): array;

    /**
     * Los de varios negocios de una vez, para no hacer una consulta por
     * tarjeta. Los que no llevan ninguno no aparecen en el mapa.
     *
     * @param string[] $businessIds
     * @return array<string, list<array{id: string, slug: string, name: string, emoji: string}>>
     */
    public function forBusinesses(array $businessIds): array;

    /**
     * Sustituye los del negocio por estos (slugs o ids). Los que no existen se
     * ignoran. Devuelve los que han quedado.
     *
     * @param string[] $badges
     * @return list<array{id: string, slug: string, name: string, emoji: string}>
     */
    public function replaceForBusiness(string $businessId, array $badges): array;

    /** El id de un badge por slug o id, o null si no existe. */
    public function resolve(string $slugOrId): ?string;
}
