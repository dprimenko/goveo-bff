<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

/**
 * Teatros del Canal (Comunidad de Madrid), por la API de su calendario de
 * WordPress. Teatro y danza sobre todo: el subnivel sale de las categorías que
 * la propia web le pone a cada espectáculo.
 */
final class TeatrosDelCanalSource extends TribeEventsSource
{
    public function name(): string
    {
        return 'teatros-canal';
    }

    protected function site(): string
    {
        return 'https://www.teatroscanal.com';
    }

    protected function venue(): array
    {
        return [
            'name'     => 'Teatros del Canal',
            'lat'      => 40.4382790,
            'lng'      => -3.7051836,
            'address'  => 'Calle de Cea Bermúdez, 1, 28003 Madrid',
            'category' => 'culture-business',
        ];
    }

    protected function classify(string $title, array $categories): array
    {
        $all = $title . ' ' . implode(' ', $categories);

        return match (true) {
            str_contains($all, 'flamenco')  => ['events-flamenco', 'events-flamenco-show'],
            str_contains($all, 'danza')     => ['events-stage', 'events-stage-dance'],
            str_contains($all, 'musical')   => ['events-stage', 'events-stage-musicals'],
            str_contains($all, 'concierto') || str_contains($all, 'música') => ['events-small-concerts', null],
            default                         => ['events-stage', 'events-stage-theater'],
        };
    }
}
