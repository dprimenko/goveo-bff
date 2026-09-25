<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

/**
 * Café La Palma (cafelapalma.com), sala de conciertos pequeños y club en
 * Malasaña, por la API de su calendario de WordPress (ver `TribeEventsSource`).
 *
 * Conciertos a las 21-22 h y, las noches de fin de semana, sesiones de club
 * (categoría «Clubbing»). Lo que la web pone en su carrusel de portada
 * («Palmeros Social Club», «Celebra tu evento…») también va en el calendario,
 * como un día entero de relleno: no es un plan y se quita.
 *
 * ⚠️ El servidor a veces se queda sin responder (se ha visto colgarse más de
 * 30 s y a la siguiente contestar en 2): una pasada puede fallar sin más.
 */
final class CafeLaPalmaSource extends TribeEventsSource
{
    use TribeShows;

    public function name(): string
    {
        return 'cafe-la-palma';
    }

    public function fetch(): iterable
    {
        return $this->groupShows(parent::fetch());
    }

    protected function site(): string
    {
        return 'https://cafelapalma.com';
    }

    protected function venue(): array
    {
        return [
            'name'     => 'Café La Palma',
            'lat'      => 40.4268263,
            'lng'      => -3.70808,
            'address'  => 'Calle de la Palma, 62, 28015 Madrid',
            'category' => 'nightlife',
        ];
    }

    /** Sin tipo se descarta (`TribeShows`): lo del carrusel. */
    protected function classify(string $title, array $categories): array
    {
        return match (true) {
            in_array('carrusel', $categories, true) => [null, null],
            in_array('clubbing', $categories, true) => ['events-nightlife', 'events-nightlife-dj-sessions'],
            default                                 => ['events-small-concerts', null],
        };
    }
}
