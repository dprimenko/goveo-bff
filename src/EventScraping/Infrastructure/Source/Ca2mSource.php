<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Application\Shows;
use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * CA2M, Centro de Arte Dos de Mayo, el museo de arte contemporáneo de la
 * Comunidad de Madrid.
 *
 * **Está en Móstoles, no en Madrid**: el evento y la sala van con su municipio,
 * pero la fuente es de la agenda de Madrid (`city()`), como Fabrik.
 *
 * Dos lecturas:
 * - **Exposiciones** de `/exposiciones`, las «actuales» con su rango (las de
 *   «Colección» son la colección del museo y no entran).
 * - **Actividades** del calendario de cada mes (`/agenda/month/AAAA-MM`): día,
 *   hora, rótulo y ficha. Casi todo son grupos de temporada con inscripción
 *   (talleres, jóvenes, universidad popular, grupo de lectura) o para
 *   colegios; sólo entran los rótulos de `KINDS`. Los pases de una misma ficha
 *   son un evento (ver `Shows`).
 *
 * Cartel y texto de las actividades, de la ficha (`enrich`).
 */
final class Ca2mSource implements EventSource
{
    private const SITE = 'https://ca2m.org';

    /** Rótulo del calendario (en minúsculas) → tipo y subnivel. */
    private const KINDS = [
        'visitas'     => ['events-experiences', 'events-experiences-guided-tours'],
        'visita'      => ['events-experiences', 'events-experiences-guided-tours'],
        'familias'    => ['events-kids', 'events-kids-workshops'],
        'concierto'   => ['events-small-concerts', null],
        'conciertos'  => ['events-small-concerts', null],
        'música'      => ['events-small-concerts', null],
        'cine'        => ['events-experiences', 'events-experiences-cinema'],
        'performance' => ['events-art', null],
        'danza'       => ['events-stage', 'events-stage-dance'],
    ];

    private const VENUE = [
        'name'    => 'CA2M Centro de Arte Dos de Mayo',
        'lat'     => 40.3246765,
        'lng'     => -3.8633232,
        'address' => 'Av. de la Constitución, 23, 28931 Móstoles',
        'website' => 'https://ca2m.org/',
    ];

    private const MUNICIPALITY = 'Móstoles';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'ca2m';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $tz    = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $tz);

        $events = $this->exhibitions($tz);

        $passes = [];
        // Este mes y el siguiente cubren los 30 días de la pasada.
        foreach ([$today, $today->modify('first day of next month')] as $month) {
            $html = $this->web->get(self::SITE . '/agenda/month/' . $month->format('Y-m'));
            if ($html === null) {
                continue;
            }
            array_push($passes, ...$this->activities($html, $month, $tz));
        }

        if ($events === [] && $passes === []) {
            throw new \RuntimeException('No se pudo leer la agenda del CA2M');
        }

        return [...$events, ...Shows::group($passes)];
    }

    /** Cartel y texto de la ficha, para las actividades (las exposiciones ya los traen). */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if (($event->imageUrl !== null && $event->description !== null) || $event->detailUrl === null) {
            return $event;
        }
        if (($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        $xp = Html::xpath($html);

        return $event->withDetails(
            Html::absolute(Html::attr($xp, '//meta[@property="og:image:secure_url"]', 'content') ?? Html::attr($xp, '//meta[@property="og:image"]', 'content'), self::SITE),
            // El primer cuerpo de texto es el de la ficha; las entradillas son
            // de lo relacionado (la publicación, la exposición).
            Html::clean(Html::text($xp, '(//div[' . Html::hasClass('field--name-body') . '])[1]'), 400),
        );
    }

    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue-ca2m',
            name: self::VENUE['name'],
            city: self::MUNICIPALITY,
            categorySlug: 'tourism-museums',
            latitude: self::VENUE['lat'],
            longitude: self::VENUE['lng'],
            address: self::VENUE['address'],
            website: self::VENUE['website'],
        );
    }

    /** @return list<ScrapedEvent> las exposiciones en curso */
    private function exhibitions(\DateTimeZone $tz): array
    {
        $html = $this->web->get(self::SITE . '/exposiciones');
        if ($html === null) {
            return [];
        }

        $xp  = Html::xpath($html);
        $out = [];
        // Sólo el bloque de las actuales: las anteriores van detrás en la misma página.
        $items = $xp->query('//section[contains(@class, "exposiciones-actuales")]//div[' . Html::hasClass('exposicion') . ']');

        foreach ($items as $item) {
            $kind  = Html::text($xp, './/div[' . Html::hasClass('field--name-field-categoria-cabecera') . ']', $item);
            $times = $xp->query('.//div[' . Html::hasClass('field--name-field-fechas') . ']//time/@datetime', $item);
            $url   = Html::absolute(Html::attr($xp, './/div[' . Html::hasClass('field--name-node-title') . ']//a', 'href', $item), self::SITE);
            $title = Html::clean(Html::text($xp, './/div[' . Html::hasClass('field--name-node-title') . ']', $item), 200);
            if ($kind !== 'Exposición' || $times === false || $times->length < 2 || $url === null || $title === null) {
                continue;
            }

            $start = $this->day((string) $times->item(0)?->nodeValue, $tz);
            $end   = $this->day((string) $times->item(1)?->nodeValue, $tz);
            if ($start === null || $end === null) {
                continue;
            }
            $about = mb_strtolower($title);

            $out[] = new ScrapedEvent(
                source: $this->name(),
                externalId: trim((string) parse_url($url, \PHP_URL_PATH), '/'),
                title: $title,
                start: $start,
                end: $end->setTime(23, 59),
                city: self::MUNICIPALITY,
                venueName: self::VENUE['name'],
                latitude: self::VENUE['lat'],
                longitude: self::VENUE['lng'],
                link: $url,
                description: Html::clean(Html::text($xp, './/div[' . Html::hasClass('field--name-field-entradilla') . ']', $item), 400),
                imageUrl: Html::absolute(Html::attr($xp, './/img', 'data-src', $item) ?? Html::attr($xp, './/img', 'src', $item), self::SITE),
                detailUrl: $url,
                venueAddress: self::VENUE['address'],
                subcategory: 'events-art',
                subtype: str_contains($about, 'fotograf') ? 'events-art-photography' : 'events-art-temporary',
            );
        }

        return $out;
    }

    /**
     * Los pases del calendario de un mes: cada día («17 Octubre») con sus
     * filas de hora, rótulo y ficha.
     *
     * El HTML viene mal cerrado (a cada fila le falta un `</div>`) y el
     * navegador lo perdona, pero libxml mete todos los días dentro del
     * primero: se corta el HTML por la cabecera de cada día y se lee cada
     * trozo por separado.
     *
     * @return list<ScrapedEvent>
     */
    private function activities(string $html, \DateTimeImmutable $month, \DateTimeZone $tz): array
    {
        $out = [];

        foreach (array_slice(explode('<div class="next-activity-date">', $html), 1) as $chunk) {
            $xp   = Html::xpath($chunk);
            $date = Html::text($xp, '//h3');
            if ($date === null || !preg_match('/^(\d{1,2})\s+(\S+)/u', $date, $d) || SpanishDate::month($d[2]) === null) {
                continue;
            }
            $base = (new \DateTimeImmutable('today', $tz))->setDate((int) $month->format('Y'), SpanishDate::month($d[2]), (int) $d[1]);

            foreach ($xp->query('//div[' . Html::hasClass('next-activities-rows-time') . ']') as $cell) {
                $row   = $cell->parentNode;
                $label = mb_strtolower((string) Html::text($xp, './/div[' . Html::hasClass('next-activities-rows-agrupadores-info') . ']/h4[1]', $row));
                $url   = Html::absolute(Html::attr($xp, './/div[' . Html::hasClass('next-activities-rows-agrupadores-info') . ']//a', 'href', $row), self::SITE);
                $title = Html::text($xp, './/div[' . Html::hasClass('next-activities-event-title') . ']', $row);
                $kind  = self::KINDS[$label] ?? null;
                if ($kind === null || $url === null || $title === null) {
                    continue;
                }

                preg_match_all('/(\d{1,2})[:.](\d{2})/', (string) $cell->textContent, $t, \PREG_SET_ORDER);
                $start = isset($t[0]) ? $base->setTime((int) $t[0][1], (int) $t[0][2]) : $base;
                $end   = isset($t[1]) ? $base->setTime((int) $t[1][1], (int) $t[1][2]) : $base->setTime(23, 59);
                // Los enlaces de la agenda van por http.
                $url = (string) preg_replace('#^http://#', 'https://', $url);

                $out[] = new ScrapedEvent(
                    source: $this->name(),
                    externalId: trim((string) parse_url($url, \PHP_URL_PATH), '/'),
                    title: Html::clean($title, 200) ?? $title,
                    start: $start,
                    end: $end > $start ? $end : null,
                    city: self::MUNICIPALITY,
                    venueName: self::VENUE['name'],
                    latitude: self::VENUE['lat'],
                    longitude: self::VENUE['lng'],
                    link: $url,
                    detailUrl: $url,
                    venueAddress: self::VENUE['address'],
                    subcategory: $kind[0],
                    subtype: $kind[1],
                );
            }
        }

        return $out;
    }

    /** `2026-09-26T12:00:00Z`: sólo cuenta el día. */
    private function day(string $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
            return null;
        }

        return (new \DateTimeImmutable('today', $tz))->setDate((int) $m[1], (int) $m[2], (int) $m[3]);
    }
}
