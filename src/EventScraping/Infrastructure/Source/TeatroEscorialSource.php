<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Application\KidsKind;

/**
 * Teatro Auditorio de San Lorenzo de El Escorial, por la API de su calendario
 * de WordPress. Toda la cartelera, no sólo lo de niños: es una sala, no una
 * agenda que lo mezcle todo. Lo infantil, a Niños.
 *
 * La cartelera es corta (tres o cuatro espectáculos a la vista) y la sala no
 * sale en los datos: va fija.
 */
final class TeatroEscorialSource extends TribeEventsSource
{
    public function name(): string
    {
        return 'teatro-escorial';
    }

    protected function municipality(): string
    {
        return 'San Lorenzo de El Escorial';
    }

    protected function site(): string
    {
        return 'https://www.teatroauditorioescorial.es';
    }

    protected function venue(): array
    {
        return [
            'name'     => 'Teatro Auditorio San Lorenzo de El Escorial',
            'lat'      => 40.5907064,
            'lng'      => -4.142947,
            'address'  => 'Parque Felipe II, s/n, 28200 San Lorenzo de El Escorial, Madrid',
            'category' => 'culture-shows',
        ];
    }

    protected function classify(string $title, array $categories): array
    {
        $all = $title . ' ' . implode(' ', $categories);

        return KidsKind::of($all) ?? match (true) {
            str_contains($all, 'flamenco')                              => ['events-flamenco', 'events-flamenco-show'],
            str_contains($all, 'danza') || str_contains($all, 'ballet') => ['events-stage', 'events-stage-dance'],
            str_contains($all, 'musical')                               => ['events-stage', 'events-stage-musicals'],
            str_contains($all, 'concierto') || str_contains($all, 'música') || str_contains($all, 'orquesta') => ['events-small-concerts', null],
            default                                                     => ['events-stage', 'events-stage-theater'],
        };
    }
}
