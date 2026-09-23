<?php

declare(strict_types=1);

namespace App\EventScraping\Application;

use App\EventScraping\Domain\ScrapedEvent;
use Doctrine\DBAL\Connection;

/**
 * De quién es un evento importado: el negocio de la sala si está en Goveo, y si
 * no, la «Agenda Goveo» de su ciudad.
 *
 * **Se busca dentro de la ciudad del evento**, no en toda la base: hay nombres
 * repetidos por España —un «Café Central» en Madrid y otro en Málaga— y colgarle
 * el concierto al de otra ciudad es peor que no colgárselo a nadie.
 *
 * Los nombres se comparan **por palabras enteras** y sin las de relleno
 * («teatro», «sala», «café»…). Por subcadena, «Teatro Español» se quedaba en
 * «espanol» y casaba con «Real Fábrica Española», a quinientos metros:
 *
 * - mismas palabras → vale (a menos de un kilómetro, si hay coordenadas);
 * - unas contenidas en otras → vale si lo común son dos palabras o más, o si es
 *   una sola y están en el mismo edificio (150 m).
 *
 * Si aun así quedan varios, gana el más cercano. Ante la duda, a la Agenda: el
 * panel permite cambiar el dueño después.
 */
final class VenueOwnerResolver
{
    private const MAX_DISTANCE_METERS  = 1000;
    private const SAME_BUILDING_METERS = 150;

    /** Palabras que no distinguen una sala de otra y estorban al comparar. */
    private const NOISE = ['sala', 'teatro', 'cafe', 'bar', 'club', 'centro', 'madrid', 'the', 'el', 'la', 'los', 'las', 'de', 'del', 'y'];

    /** @var array<string, ?string> */
    private array $cache = [];

    public function __construct(private readonly Connection $db) {}

    /** Id del negocio, o `null` si no hay ninguno claro. */
    public function businessFor(ScrapedEvent $event): ?string
    {
        $words = self::words($event->venueName);
        if ($words === []) {
            return null;
        }

        $cacheKey = implode('|', [$event->city, implode(' ', $words), $event->latitude, $event->longitude]);
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $hasPoint = $event->latitude !== null && $event->longitude !== null;

        // Filtro grueso en la base —misma ciudad y alguna palabra en común—; el
        // fino, por palabras enteras, se hace aquí abajo.
        $like = implode(' OR ', array_fill(0, count($words), "unaccent(lower(name)) LIKE ?"));
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, name,
                    CASE WHEN location IS NULL OR NOT ? THEN NULL
                         ELSE ST_Distance(location::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)
                    END AS distance
               FROM business
              WHERE deleted_at IS NULL
                AND unaccent(lower(city)) = unaccent(lower(?))
                AND ({$like})
              ORDER BY distance ASC NULLS LAST",
            [$hasPoint, $event->longitude ?? 0.0, $event->latitude ?? 0.0, $event->city, ...array_map(fn ($w) => "%{$w}%", $words)],
            [\Doctrine\DBAL\ParameterType::BOOLEAN],
        );

        return $this->cache[$cacheKey] = $this->pick($words, $rows, $hasPoint);
    }

    /**
     * @param list<string>                                           $venue
     * @param list<array{id: string, name: ?string, distance: mixed}> $rows
     */
    private function pick(array $venue, array $rows, bool $hasPoint): ?string
    {
        $matches = [];

        foreach ($rows as $row) {
            $name     = self::words((string) $row['name']);
            $distance = $row['distance'] !== null ? (float) $row['distance'] : null;

            if ($name === [] || ($distance !== null && $distance > self::MAX_DISTANCE_METERS)) {
                continue;
            }

            $common = array_intersect($venue, $name);
            $same   = count($common) === count($venue) && count($common) === count($name);
            $nested = count($common) === min(count($venue), count($name));

            $accepted = $same
                || ($nested && count($common) >= 2)
                || ($nested && $distance !== null && $distance <= self::SAME_BUILDING_METERS);

            if ($accepted) {
                $matches[] = $row + ['known_distance' => $distance !== null];
            }
        }

        if ($matches === []) {
            return null;
        }

        // Ordenados por distancia desde la consulta. Si el evento no trae
        // coordenadas, dos candidatos iguales son un empate y no se elige.
        if (!$hasPoint || !$matches[0]['known_distance']) {
            return count($matches) === 1 ? $matches[0]['id'] : null;
        }

        return $matches[0]['id'];
    }

    /** @return list<string> */
    private static function words(string $name): array
    {
        $name = mb_strtolower($name);
        $name = strtr($name, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ç' => 'c']);
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? '';

        return array_values(array_unique(array_filter(
            explode(' ', $name),
            fn (string $w) => mb_strlen($w) >= 2 && !in_array($w, self::NOISE, true),
        )));
    }
}
