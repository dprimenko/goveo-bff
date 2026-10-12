<?php

declare(strict_types=1);

namespace App\Backoffice\Application;

use App\GeoStories\Infrastructure\Geocoding\GooglePlaceLookup;
use Doctrine\DBAL\Connection;

/**
 * Dónde es cada vídeo, para leerlo en el panel: código postal y municipio
 * («28901 Getafe»). Lo pidió el usuario el 12-10-2026: con la cartelera de toda
 * la comunidad, «Madrid» no decía si algo era en Getafe o en Chamberí.
 *
 * Se saca de las coordenadas **del vídeo** (`GooglePlaceLookup`) y se guarda en
 * `meta.place` **con las coordenadas con que se calculó**: si el vídeo se mueve
 * —desde el panel, al editarlo en la app— ya no coinciden y se recalcula solo,
 * sin tener que acordarse de borrarlo en cada sitio que cambia la ubicación.
 *
 * Se calcula **al listar**, sólo lo que no lo tiene y para la página que se
 * enseña: lo de siempre sale de la base sin llamar a nadie, y lo nuevo —una
 * pasada de importación— se va completando según se mira. Sin coordenadas
 * propias, el del negocio dueño (su ciudad y `meta.postal_code`).
 */
final class GeoStoryPlaces
{
    /** Menos de esto entre coordenadas es el mismo sitio (~1 m). */
    private const SAME_POINT = 0.00001;

    public function __construct(
        private readonly Connection $db,
        private readonly GooglePlaceLookup $lookup,
    ) {}

    /**
     * @param list<array<string, mixed>> $rows filas del listado, con `id`, `meta`,
     *                                         `lat`, `lng`, `business_city` y
     *                                         `business_postal_code`
     *
     * @return array<string, array{postal_code: ?string, city: ?string}|null> por id
     */
    public function forRows(array $rows): array
    {
        $places  = [];
        $missing = [];

        foreach ($rows as $row) {
            $id = (string) $row['id'];

            if ($row['lat'] === null || $row['lng'] === null) {
                $places[$id] = self::orNull($row['business_postal_code'] ?? null, $row['business_city'] ?? null);
                continue;
            }

            $meta  = is_string($row['meta'] ?? null) ? (json_decode($row['meta'], true) ?: []) : [];
            $place = is_array($meta['place'] ?? null) ? $meta['place'] : null;
            if ($place !== null
                && abs((float) ($place['lat'] ?? 999) - (float) $row['lat']) < self::SAME_POINT
                && abs((float) ($place['lng'] ?? 999) - (float) $row['lng']) < self::SAME_POINT
            ) {
                $places[$id] = self::orNull($place['postal_code'] ?? null, $place['city'] ?? null);
                continue;
            }

            $missing[$id] = ['lat' => (float) $row['lat'], 'lng' => (float) $row['lng']];
            // Mientras Google no conteste —o en local, sin clave—, lo del negocio.
            $places[$id] = self::orNull($row['business_postal_code'] ?? null, $row['business_city'] ?? null);
        }

        foreach ($this->lookup->placesFor($missing) as $id => $found) {
            $this->db->executeStatement(
                "UPDATE geostories
                    SET meta = (COALESCE(meta::jsonb, '{}'::jsonb)
                                || jsonb_build_object('place', jsonb_build_object(
                                       'postal_code', ?::text, 'city', ?::text, 'lat', ?::float8, 'lng', ?::float8)))::json
                  WHERE id = ?",
                [$found['postal_code'], $found['city'], $missing[$id]['lat'], $missing[$id]['lng'], $id],
            );
            $places[$id] = self::orNull($found['postal_code'], $found['city']) ?? $places[$id];
        }

        return $places;
    }

    /** @return array{postal_code: ?string, city: ?string}|null */
    private static function orNull(mixed $postalCode, mixed $city): ?array
    {
        $postalCode = is_string($postalCode) && trim($postalCode) !== '' ? trim($postalCode) : null;
        $city       = is_string($city) && trim($city) !== '' ? trim($city) : null;

        return $postalCode === null && $city === null ? null : ['postal_code' => $postalCode, 'city' => $city];
    }
}
