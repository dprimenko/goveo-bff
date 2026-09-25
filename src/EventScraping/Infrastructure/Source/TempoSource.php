<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

/**
 * Tempo Club (tempoclub.es), club de jazz, soul y funk junto a Plaza de España,
 * por la API de su calendario de WordPress (ver `TribeEventsSource`).
 *
 * Tiene dos cosas: conciertos (categoría «Conciertos») y sesiones de DJ en la
 * Sala Lounge o la Sala Disco («DJs»). Las sesiones se repiten con el mismo
 * nombre cada semana —«ROBERTO TEMPO (Sala Lounge)», «JAZZ KISSA»— y salen como
 * un evento con su rango (`TribeShows`), no uno por noche.
 *
 * Los «EVENTO PRIVADO» (la sala cerrada para un alquiler) no son un plan.
 */
final class TempoSource extends TribeEventsSource
{
    use TribeShows;

    public function name(): string
    {
        return 'tempo';
    }

    public function fetch(): iterable
    {
        return $this->groupShows(parent::fetch());
    }

    protected function site(): string
    {
        return 'https://tempoclub.es';
    }

    protected function venue(): array
    {
        return [
            'name'     => 'Tempo Club',
            'lat'      => 40.425275,
            'lng'      => -3.711892,
            'address'  => 'Calle del Duque de Osuna, 8, 28015 Madrid',
            'category' => 'nightlife',
        ];
    }

    /**
     * Sin tipo se descarta (`TribeShows`): el evento privado. Una sesión
     * «Conciertos» y «DJs» a la vez es electrónica en directo («AFTERAPIA»,
     * «MECHANIC MOODS LIVE SESSION»): noche, no concierto.
     */
    protected function classify(string $title, array $categories): array
    {
        if (str_contains($title, 'evento privado')) {
            return [null, null];
        }

        $djs = in_array('djs', $categories, true);

        return match (true) {
            $djs && (in_array('conciertos', $categories, true) || str_contains($title, 'electr')) => ['events-nightlife', 'events-nightlife-electronic'],
            $djs                                                                                    => ['events-nightlife', 'events-nightlife-dj-sessions'],
            default                                                                                 => ['events-small-concerts', null],
        };
    }
}
