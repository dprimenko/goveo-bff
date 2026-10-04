<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Museo Casa Natal de Cervantes (Alcalá de Henares), de la Comunidad de Madrid.
 *
 * **Está en Alcalá, no en Madrid**: el evento y la sala van con su municipio,
 * pero la fuente es de la agenda de Madrid (`city()`), como Fabrik.
 *
 * Su web es un WordPress en el que cada actividad es una entrada de blog con
 * las fechas en el texto. **Sólo se leen las exposiciones** (categoría
 * `EXHIBITIONS` de la API), que siempre las escriben igual: «Fechas: del 29 de
 * septiembre al 7 de marzo de 2027». Los espectáculos y talleres las cuentan a
 * su manera («l 6 , 7 de octubre, un pase diario a las 18.00h; día 8 doble
 * sesión…») y no se pueden leer con fiabilidad.
 */
final class CasaNatalCervantesSource implements EventSource
{
    private const API = 'https://museocasanataldecervantes.org/wp-json/wp/v2/posts?categories=81&per_page=10&_embed=wp:featuredmedia';

    private const VENUE = [
        'name'    => 'Museo Casa Natal de Cervantes',
        'lat'     => 40.482202,
        'lng'     => -3.3670805,
        'address' => 'Calle Mayor, 48, 28801 Alcalá de Henares',
        'website' => 'https://museocasanataldecervantes.org/',
    ];

    private const MUNICIPALITY = 'Alcalá de Henares';

    private const DATES = '/fechas?:\s*del\s+(\d{1,2})\s+de\s+(\pL+)(?:\s+(?:de|del)\s+(\d{4}))?\s+al\s+(\d{1,2})\s+de\s+(\pL+)\s+(?:de|del)\s+(\d{4})/iu';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'casa-natal-cervantes';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $raw   = $this->web->get(self::API);
        $posts = $raw !== null ? json_decode($raw, true) : null;
        if (!is_array($posts)) {
            throw new \RuntimeException('No se pudieron leer las exposiciones de la Casa Natal de Cervantes');
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));

        foreach ($posts as $post) {
            $text  = Html::clean($post['content']['rendered'] ?? null, 100_000) ?? '';
            $title = Html::clean($post['title']['rendered'] ?? null, 200);
            $url   = is_string($post['link'] ?? null) ? $post['link'] : null;
            if ($title === null || $url === null || !preg_match(self::DATES, $text, $m)) {
                continue;
            }

            $from = SpanishDate::month($m[2]);
            $to   = SpanishDate::month($m[5]);
            $year = (int) $m[6];
            if ($from === null || $to === null || !checkdate($to, (int) $m[4], $year)) {
                continue;
            }
            $end = $today->setDate($year, $to, (int) $m[4]);
            // Sin año en el inicio, es el del fin; o el anterior si así el
            // inicio quedaría después («del 29 de septiembre al 7 de marzo de 2027»).
            $startYear = $m[3] !== '' ? (int) $m[3] : ($from > $to ? $year - 1 : $year);
            if ($end < $today || !checkdate($from, (int) $m[1], $startYear)) {
                continue;
            }

            $about = mb_strtolower($title);

            yield new ScrapedEvent(
                source: $this->name(),
                externalId: 'post-' . ($post['id'] ?? md5($url)),
                title: trim($title, " «»\"'"),
                start: $today->setDate($startYear, $from, (int) $m[1]),
                end: $end->setTime(23, 59),
                city: self::MUNICIPALITY,
                venueName: self::VENUE['name'],
                latitude: self::VENUE['lat'],
                longitude: self::VENUE['lng'],
                link: $url,
                description: Html::clean($post['excerpt']['rendered'] ?? null, 400),
                imageUrl: $post['_embedded']['wp:featuredmedia'][0]['source_url'] ?? null,
                detailUrl: $url,
                venueAddress: self::VENUE['address'],
                subcategory: 'events-art',
                subtype: str_contains($about, 'fotograf') ? 'events-art-photography' : 'events-art-temporary',
            );
        }
    }

    /** Todo viene en la API. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue-casa-natal-cervantes',
            name: self::VENUE['name'],
            city: self::MUNICIPALITY,
            categorySlug: 'tourism-museums',
            latitude: self::VENUE['lat'],
            longitude: self::VENUE['lng'],
            address: self::VENUE['address'],
            website: self::VENUE['website'],
        );
    }
}
