<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Infrastructure\ICal;

/**
 * Agenda del Ayuntamiento de Alpedrete (alpedrete.es/eventos), lo de niños.
 *
 * Es «The Events Calendar», pero con la API cerrada: se lee su iCal
 * (`?ical=1`), que trae cartel, sitio y categoría (Cuentacuentos, Juventud,
 * Espacio para familias…).
 */
final class AlpedreteSource extends MunicipalAgendaSource
{
    private const ICAL = 'https://www.alpedrete.es/eventos/?ical=1';

    public function name(): string
    {
        return 'alpedrete';
    }

    protected function municipality(): string
    {
        return 'Alpedrete';
    }

    protected function center(): array
    {
        return [40.65887, -4.0234807, 'Pl. de la Villa, 1, 28430 Alpedrete, Madrid'];
    }

    protected function places(): array
    {
        return [
            'biblioteca-municipal' => ['Biblioteca Municipal', 'Pl. Francisco Rabal, 2, 28430 Alpedrete, Madrid', 40.6569573, -4.0237427],
        ];
    }

    protected function raw(): iterable
    {
        $ics = $this->web->get(self::ICAL);
        if ($ics === null || !str_contains($ics, 'BEGIN:VCALENDAR')) {
            throw new \RuntimeException('No se pudo leer el calendario de Alpedrete');
        }

        $tz = new \DateTimeZone('Europe/Madrid');
        foreach (ICal::events($ics) as $e) {
            // «Biblioteca Municipal, Plaza de Francisco Rabal, 2, Alpedrete…»
            $location = $e['LOCATION'] ?? '';
            [$place]  = explode(',', $location, 2);

            yield [
                'id'          => strtok($e['UID'] ?? '', '@') ?: null,
                'title'       => $e['SUMMARY'] ?? null,
                'start'       => ICal::date($e['DTSTART'] ?? null, $tz),
                'end'         => ICal::date($e['DTEND'] ?? null, $tz),
                // Lo que se hace por internet lo pone «en Madrid».
                'place'       => trim($location) === 'Madrid' ? 'Online' : trim($place),
                'address'     => str_contains($location, ',') ? $location : null,
                'description' => $e['DESCRIPTION'] ?? null,
                'image'       => $e['ATTACH'] ?? null,
                'url'         => $e['URL'] ?? null,
                'tags'        => $e['CATEGORIES'] ?? '',
            ];
        }
    }
}
