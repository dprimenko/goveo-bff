<?php

declare(strict_types=1);

namespace App\EventScraping\Application;

use App\EventScraping\Domain\ScrapedEvent;
use Doctrine\DBAL\Connection;

/**
 * Un evento de una ticketera con afiliación (El Corte Inglés por Awin) que ya
 * entró por otra fuente —la web de la propia sala— no se duplica: **se queda el
 * que estaba y se le cambia el enlace** por el de afiliado, que lleva comisión.
 * Lo decidió el usuario el 12-10-2026.
 *
 * El mismo evento es: **otro importado** (`external_ref`; lo que sube un negocio
 * con su propio enlace no se toca), de Eventos, sin borrar, a menos de un
 * kilómetro, con fechas que se pisan y el mismo título. El título se compara por
 * palabras, sin tildes ni las de relleno, y vale que uno contenga al otro: El
 * Corte Inglés añade el artista («Buenas Noches in The Night - Facu Díaz») y la
 * sala a veces no.
 */
final class AffiliateRelinker
{
    private const MAX_DISTANCE_METERS = 1000;

    /** Palabras que no distinguen un espectáculo de otro. */
    private const NOISE = ['teatro', 'madrid', 'entradas', 'concierto', 'the', 'el', 'la', 'los', 'las', 'lo', 'de', 'del',
        'y', 'e', 'en', 'con', 'a', 'al', 'un', 'una', 'por', 'para', 'es', 'que'];

    public function __construct(private readonly Connection $db) {}

    /**
     * El evento que ya hay de otra fuente, o nulo.
     *
     * @return array{id: string, title: string, source: ?string, link: ?string}|null
     */
    public function findSame(ScrapedEvent $event): ?array
    {
        if ($event->latitude === null || $event->longitude === null) {
            return null;
        }

        $rows = $this->db->fetchAllAssociative(
            "SELECT g.id, g.title, g.meta->>'origin_source' AS source, g.meta->>'link_url' AS link
               FROM geostories g
               JOIN categories c ON c.id = g.category_id AND c.slug = 'events'
              WHERE g.deleted_at IS NULL
                AND g.external_ref IS NOT NULL
                AND g.external_ref NOT LIKE ?
                AND g.location IS NOT NULL
                AND ST_DWithin(g.location::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
                AND g.ended_at >= ?
                AND g.started_at <= ?
              ORDER BY g.location::geography <-> ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography",
            [
                addcslashes($event->source, '%_') . ':%',
                $event->longitude, $event->latitude, self::MAX_DISTANCE_METERS,
                $event->start->format(\DATE_ATOM),
                ($event->end ?? $event->start)->format(\DATE_ATOM),
                $event->longitude, $event->latitude,
            ],
        );

        foreach ($rows as $row) {
            if (self::sameTitle($event->title, (string) $row['title'])) {
                return $row;
            }
        }

        return null;
    }

    /** Pone el enlace de afiliado. `false` si ya lo tenía. */
    public function relink(string $id, string $url, string $action): bool
    {
        return $this->db->executeStatement(
            "UPDATE geostories
                SET meta = (COALESCE(meta::jsonb, '{}'::jsonb)
                            || jsonb_build_object('link_url', ?::text, 'link_action', ?::text, 'link_partner', 'awin'))::json,
                    updated_at = NOW()
              WHERE id = ? AND deleted_at IS NULL
                AND (meta->>'link_url') IS DISTINCT FROM ?",
            [$url, $action, $id, $url],
        ) > 0;
    }

    public static function sameTitle(string $a, string $b): bool
    {
        $wa = self::words($a);
        $wb = self::words($b);
        if ($wa === [] || $wb === []) {
            return false;
        }
        if ($wa === $wb) {
            return true;
        }

        [$short, $long] = count($wa) <= count($wb) ? [$wa, $wb] : [$wb, $wa];

        // Una sola palabra en común es poco: «Carmen» casaría con medio cartel.
        return count($short) >= 2 && array_diff($short, $long) === [];
    }

    /** @return list<string> palabras distintas, ordenadas */
    private static function words(string $title): array
    {
        $title = mb_strtolower($title);
        $title = strtr($title, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ò' => 'o', 'ï' => 'i', 'ç' => 'c']);
        $words = preg_split('/[^a-z0-9]+/', $title, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_unique(array_diff($words, self::NOISE)));
        sort($words);

        return $words;
    }
}
