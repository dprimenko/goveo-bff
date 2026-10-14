<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

/**
 * Agenda del Ayuntamiento de Villalbilla (villalbilla.es/eventos), lo de niños.
 *
 * API de «The Events Calendar», pero no por `TribeEventsSource`: aquí no hay
 * una sala sino todo el pueblo. ⚠️ La respuesta trae caracteres de control sin
 * escapar dentro de los textos y `json_decode` la rechaza entera (como el
 * fichero del Ayuntamiento de Madrid): se cambian por espacios antes.
 */
final class VillalbillaSource extends MunicipalAgendaSource
{
    private const API       = 'https://villalbilla.es/wp-json/tribe/events/v1/events';
    private const MAX_PAGES = 4;

    public function name(): string
    {
        return 'villalbilla';
    }

    protected function municipality(): string
    {
        return 'Villalbilla';
    }

    protected function center(): array
    {
        return [40.432443, -3.2986047, 'Pl. Mayor, 2, 28810 Villalbilla, Madrid'];
    }

    protected function raw(): iterable
    {
        $tz    = new \DateTimeZone('Europe/Madrid');
        $today = (new \DateTimeImmutable('today', $tz))->format('Y-m-d');

        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $raw  = $this->web->get(sprintf('%s?start_date=%s&per_page=50&page=%d', self::API, $today, $page));
            $data = $raw !== null ? json_decode((string) preg_replace('/[\x00-\x1F]/', ' ', $raw), true) : null;
            if (!is_array($data) || !is_array($data['events'] ?? null)) {
                if ($page === 1) {
                    throw new \RuntimeException('No se pudo leer la agenda de Villalbilla');
                }
                break;
            }

            foreach ($data['events'] as $e) {
                $venue = is_array($e['venue'] ?? null) ? $e['venue'] : [];
                yield [
                    'id'          => $e['id'] ?? null,
                    'title'       => $e['title'] ?? null,
                    'start'       => $this->date($e['start_date'] ?? null, $tz),
                    'end'         => ($e['all_day'] ?? false) === true ? null : $this->date($e['end_date'] ?? null, $tz),
                    'place'       => $venue['venue'] ?? '',
                    'address'     => $venue['address'] ?? null,
                    'lat'         => $venue['geo_lat'] ?? null,
                    'lng'         => $venue['geo_lng'] ?? null,
                    'description' => $e['excerpt'] ?? $e['description'] ?? null,
                    'image'       => $e['image']['url'] ?? null,
                    'url'         => $e['url'] ?? null,
                    'tags'        => implode(' ', array_map(
                        static fn ($c) => (string) ($c['name'] ?? ''),
                        is_array($e['categories'] ?? null) ? $e['categories'] : [],
                    )),
                ];
            }

            if ($page >= (int) ($data['total_pages'] ?? 1)) {
                break;
            }
        }
    }

    private function date(mixed $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        return is_string($raw) ? (\DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, $tz) ?: null) : null;
    }
}
