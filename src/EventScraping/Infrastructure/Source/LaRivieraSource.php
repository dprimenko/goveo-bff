<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

/**
 * La Riviera, por la API de su calendario de WordPress. Conciertos, y las
 * sesiones de club de madrugada van a Noche y fiesta.
 */
final class LaRivieraSource extends TribeEventsSource
{
    public function name(): string
    {
        return 'la-riviera';
    }

    protected function site(): string
    {
        return 'https://salariviera.com';
    }

    protected function venue(): array
    {
        return [
            'name'     => 'La Riviera',
            'lat'      => 40.4129999,
            'lng'      => -3.7221514,
            'address'  => 'Paseo Bajo de la Virgen del Puerto, s/n, 28005 Madrid',
            'category' => 'nightlife',
        ];
    }

    protected function classify(string $title, array $categories): array
    {
        $all = $title . ' ' . implode(' ', $categories);

        return preg_match('/\b(club|sesi[oó]n|dj|fiesta|party)\b/u', $all)
            ? ['events-nightlife', 'events-nightlife-dj-sessions']
            : ['events-small-concerts', null];
    }
}
