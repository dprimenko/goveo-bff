<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Infrastructure\JsonLd;

/**
 * Specka, club de techno de la calle Orense. La portada lleva su agenda como
 * datos estructurados, pero **dentro** del nodo del club (`NightClub.event`),
 * y `JsonLd` sólo baja por `@graph`, `itemListElement`, `item` y `subEvent`:
 * por eso aquí se sacan esos eventos a un bloque propio antes de dárselos a la
 * base. Si se añade `event` a `JsonLd::collect`, esto sobra.
 *
 * Cada evento enlaza a su ficha en Resident Advisor, que es también donde se
 * compran las entradas. No se abre: todo lo necesario viene en la portada.
 */
final class SpeckaSource extends JsonLdEventSource
{
    private const HOME = 'https://specka.es/';

    public function name(): string
    {
        return 'specka';
    }

    protected function homeUrl(): string
    {
        return self::HOME;
    }

    protected function pages(): iterable
    {
        $html = $this->web->get(self::HOME);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la portada de Specka');
        }

        preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $m);
        foreach ($m[1] as $raw) {
            $data = json_decode(trim($raw), true);
            if (is_array($data) && isset($data['event']) && is_array($data['event'])) {
                yield '<script type="application/ld+json">' . json_encode(array_values($data['event'])) . '</script>';
            }
        }
    }

    /** La imagen viene siempre en los datos: no hay nada que ir a buscar a RA. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    protected function venue(array $node): ?array
    {
        return [
            'name'     => 'Specka',
            'lat'      => 40.4518227,
            'lng'      => -3.694827,
            'address'  => 'Calle de Orense, 26, 28020 Madrid',
            'website'  => self::HOME,
            'category' => 'nightlife',
        ];
    }

    /**
     * Casi todo son sesiones de techno y house de madrugada. Las de tarde
     * («Tardes de Trance», «Specka Club Tardes») son tardeo, lo que se anuncia
     * como concierto o empieza pronto sin ser de tarde es concierto de sala, y
     * las noches temáticas se dicen en el título.
     */
    protected function classify(array $node): array
    {
        $title = (string) JsonLd::text($node['name'] ?? null, 'name');
        $hour  = preg_match('/T(\d{2}):/', (string) ($node['startDate'] ?? ''), $h) ? (int) $h[1] : 23;

        return match (true) {
            (bool) preg_match('/\btardes?\b|\btardeo\b/iu', $title)           => ['events-nightlife', 'events-nightlife-tardeo'],
            (bool) preg_match('/\b(in concert|concierto|en directo)\b/iu', $title),
            $hour >= 12 && $hour < 22                                          => ['events-small-concerts', null],
            (bool) preg_match('/tem[aá]tic/iu', $title)                        => ['events-nightlife', 'events-nightlife-theme-parties'],
            default                                                            => ['events-nightlife', 'events-nightlife-electronic'],
        };
    }
}
