<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure;

/**
 * Los eventos que una página publica como datos estructurados
 * (`<script type="application/ld+json">` con `schema.org/Event`), que es lo que
 * lee Google para enseñar eventos. Muchas webs de salas lo llevan aunque su
 * maqueta sea imposible de leer, y es mucho más estable que la maqueta: por eso
 * se prefiere a leer el HTML a mano.
 *
 * Resuelve las referencias por `@id`: hay webs (los tablaos) que ponen la imagen
 * o el sitio en otro nodo y en el evento sólo dejan `{"@id": "…#img"}`.
 */
final class JsonLd
{
    private const EVENT_TYPES = [
        'Event', 'MusicEvent', 'TheaterEvent', 'DanceEvent', 'ComedyEvent', 'ExhibitionEvent',
        'Festival', 'SocialEvent', 'ScreeningEvent', 'EducationEvent', 'LiteraryEvent', 'FoodEvent',
    ];

    /** @return list<array<string, mixed>> los nodos Event, con sus referencias ya resueltas */
    public static function events(string $html): array
    {
        $nodes = [];
        if (!preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $m)) {
            return [];
        }

        foreach ($m[1] as $raw) {
            $data = json_decode(trim(html_entity_decode($raw, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')), true)
                ?? json_decode(trim($raw), true);
            if (is_array($data)) {
                self::collect($data, $nodes);
            }
        }

        $byId = [];
        foreach ($nodes as $node) {
            if (isset($node['@id']) && is_string($node['@id'])) {
                $byId[$node['@id']] ??= $node;
            }
        }

        $events = [];
        foreach ($nodes as $node) {
            $types = (array) ($node['@type'] ?? []);
            if (array_intersect($types, self::EVENT_TYPES) !== []) {
                $events[] = self::resolve($node, $byId, 0);
            }
        }

        return $events;
    }

    /** El primer texto de un campo que puede venir como texto, lista u objeto con `url`/`name`. */
    public static function text(mixed $value, string $key = 'url'): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return $value === [] ? null : self::text($value[0], $key);
            }

            return self::text($value[$key] ?? null, $key);
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $nodes */
    private static function collect(mixed $data, array &$nodes): void
    {
        if (!is_array($data)) {
            return;
        }
        if (array_is_list($data)) {
            foreach ($data as $item) {
                self::collect($item, $nodes);
            }

            return;
        }

        $nodes[] = $data;
        foreach (['@graph', 'itemListElement', 'item', 'subEvent'] as $key) {
            if (isset($data[$key])) {
                self::collect($data[$key], $nodes);
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $byId
     *
     * @return array<string, mixed>
     */
    private static function resolve(array $node, array $byId, int $depth): array
    {
        if ($depth > 3) {
            return $node;
        }

        foreach ($node as $key => $value) {
            if (is_array($value) && !array_is_list($value) && count($value) === 1 && isset($value['@id'], $byId[$value['@id']])) {
                $node[$key] = self::resolve($byId[$value['@id']], $byId, $depth + 1);
            }
        }

        return $node;
    }
}
