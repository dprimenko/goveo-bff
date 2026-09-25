<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Application\Shows;
use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\JsonLd;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Teatro La Latina (teatrolalatina.es). La portada es la cartelera: una tarjeta
 * por espectáculo con su foto, el enlace de compra y la fecha **escrita a
 * mano** —«El 9 y 11 de octubre de 2026», «Los días 30 de noviembre y 1 de
 * diciembre de 2026», «Desde el 11 de septiembre de 2026»—, sin hora.
 *
 * Ni la API de WordPress (los espectáculos son entradas sin fechas) ni los datos
 * estructurados de la ficha (casi ninguno trae `startDate`) sirven mejor, y la
 * venta (Onebox) está detrás del desafío de Cloudflare. Así que las fechas son
 * las de la tarjeta, y cada función dura su día entero.
 *
 * «Desde el…» no tiene fin anunciado: el espectáculo está en cartel y se da por
 * hecho que sigue el próximo mes (ver `OPEN_RUN`). «Estreno en noviembre», sin
 * día, se salta hasta que lo pongan.
 */
final class TeatroLaLatinaSource implements EventSource
{
    private const HOME = 'https://www.teatrolalatina.es/';

    /** Hasta cuándo se da por en cartel lo que sólo dice «Desde el…». */
    private const OPEN_RUN = '+30 days';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'teatro-la-latina';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::HOME);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la cartelera del Teatro La Latina');
        }

        $xp           = Html::xpath($html);
        $performances = [];

        foreach ($xp->query('//div[' . Html::hasClass('obra') . ']') as $card) {
            // Las tarjetas van tras el título de su sección: «Música y
            // conciertos» (`musical`), «Eventos», los abonos…
            $section = (string) Html::attr($xp, './preceding-sibling::h1[1]', 'class', $card);
            $title   = Html::clean(Html::text($xp, './/span[' . Html::hasClass('titulo') . ']', $card), 200);
            $page    = Html::attr($xp, './/a[' . Html::hasClass('imagen') . ']', 'href', $card);
            $image   = $this->largest($xp, $card);
            $tickets = Html::attr($xp, './/a[' . Html::hasClass('asset-boton') . ']', 'href', $card);
            $days    = $this->days((string) Html::text($xp, './/span[' . Html::hasClass('fecha') . ']', $card));
            if ($section === 'abonos' || $title === null || $page === null || $image === null || $days === []) {
                continue;
            }

            foreach ($days as [$start, $end]) {
                $performances[] = new ScrapedEvent(
                    source: $this->name(),
                    // El espectáculo, no el día: `Shows` junta sus pases.
                    externalId: basename(trim((string) parse_url($page, \PHP_URL_PATH), '/')),
                    title: $title,
                    start: $start,
                    end: $end,
                    city: $this->city(),
                    venueName: 'Teatro La Latina',
                    latitude: 40.4114334,
                    longitude: -3.7087451,
                    link: $tickets ?? $page,
                    linkAction: $tickets !== null ? 'buy' : 'info',
                    imageUrl: $image,
                    detailUrl: $page,
                    venueAddress: 'Plaza de la Cebada, 2, 28005 Madrid',
                    // La sección, de momento: el tipo sale de la ficha.
                    subcategory: $section,
                );
            }
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));
        $shows = [];
        foreach (Shows::group($performances) as $show) {
            // La ficha sólo de lo que puede entrar en las próximas semanas.
            if (($show->end ?? $show->start) >= $today && $show->start <= $today->modify('+60 days')) {
                $shows[] = $this->detailed($show);
            }
        }

        return $shows;
    }

    /** La ficha ya se abrió al leer la cartelera. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: 'Teatro La Latina',
            city: $this->city(),
            categorySlug: 'culture-business',
            latitude: 40.4114334,
            longitude: -3.7087451,
            address: 'Plaza de la Cebada, 2, 28005 Madrid',
            website: self::HOME,
        );
    }

    /**
     * Los días de la fecha escrita, cada uno entero. Los días sin mes toman el
     * del siguiente que lo tenga («el 9 y 11 de octubre»), y sin año, el que
     * deduce `SpanishDate`.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private function days(string $text): array
    {
        $text = mb_strtolower($text);
        if (!preg_match_all('/\b(\d{1,2})\b(?:\s+de\s+([a-zé]+))?(?:\s+de\s+(\d{4}))?/u', $text, $m, \PREG_SET_ORDER)) {
            return [];
        }

        $tz    = new \DateTimeZone('Europe/Madrid');
        $month = null;
        $year  = null;
        $dates = [];
        foreach (array_reverse($m) as $match) {
            $month = SpanishDate::month($match[2] ?? '') ?? $month;
            $year  = isset($match[3]) && $match[3] !== '' ? (int) $match[3] : $year;
            if ($month === null) {
                return [];
            }
            $date = $year !== null && checkdate($month, (int) $match[1], $year)
                ? (new \DateTimeImmutable('today', $tz))->setDate($year, $month, (int) $match[1])
                : SpanishDate::build((int) $match[1], $month, null);
            if ($date !== null) {
                array_unshift($dates, $date);
            }
        }
        if ($dates === []) {
            return [];
        }

        // «Del 3 al 20 de diciembre»: un rango, no dos días.
        if (count($dates) === 2 && preg_match('/\bdel\b.*\bal\b/u', $text)) {
            return [[$dates[0], $dates[1]->setTime(23, 59)]];
        }
        // «Desde el 11 de septiembre»: en cartel, sin fin anunciado.
        if (str_starts_with($text, 'desde')) {
            $today = new \DateTimeImmutable('today', $tz);

            return [[$dates[0], max($dates[0], $today)->modify(self::OPEN_RUN)->setTime(23, 59)]];
        }

        return array_map(fn (\DateTimeImmutable $d) => [$d, $d->setTime(23, 59)], $dates);
    }

    /**
     * La sinopsis de la ficha (en sus datos estructurados), y con ella el tipo:
     * el título no dice que «Efectiviwonder» es un monólogo, la sinopsis sí.
     * Se hace al leer la cartelera porque el tipo no se cambia en `enrich`.
     */
    private function detailed(ScrapedEvent $e): ScrapedEvent
    {
        $html        = $e->detailUrl !== null ? $this->web->get($e->detailUrl) : null;
        $description = null;
        foreach ($html !== null ? JsonLd::events($html) : [] as $node) {
            $description = Html::clean(JsonLd::text($node['description'] ?? null, 'text'), 400);
            break;
        }
        if ($description === null && $html !== null) {
            $description = Html::clean(Html::attr(Html::xpath($html), '//meta[@property="og:description"]', 'content'), 400);
        }

        [$subcategory, $subtype] = $this->classify((string) $e->subcategory, mb_strtolower($e->title), mb_strtolower((string) $description));

        return new ScrapedEvent(
            source: $e->source,
            externalId: $e->externalId,
            title: $e->title,
            start: $e->start,
            end: $e->end,
            city: $e->city,
            venueName: $e->venueName,
            latitude: $e->latitude,
            longitude: $e->longitude,
            link: $e->link,
            linkAction: $e->linkAction,
            description: $description,
            imageUrl: $e->imageUrl,
            detailUrl: $e->detailUrl,
            weekdays: $e->weekdays,
            venueAddress: $e->venueAddress,
            subcategory: $subcategory,
            subtype: $subtype,
        );
    }

    /**
     * La foto más grande del `srcset`: la miniatura es `…-700x315.jpg`, y no
     * vale quitarle el tamaño al nombre —hay una subida que se llama «.jpg»—.
     */
    private function largest(\DOMXPath $xp, \DOMNode $card): ?string
    {
        $img  = './/a[' . Html::hasClass('imagen') . ']//img';
        $best = Html::attr($xp, $img, 'src', $card);
        $max  = 0;
        foreach (explode(',', (string) Html::attr($xp, $img, 'srcset', $card)) as $candidate) {
            if (preg_match('/^\s*(\S+)\s+(\d+)w/', $candidate, $c) && (int) $c[2] > $max) {
                [$best, $max] = [$c[1], (int) $c[2]];
            }
        }

        return $best;
    }

    /** @return array{0: string, 1: ?string} */
    private function classify(string $section, string $title, string $description): array
    {
        return match (true) {
            $section === 'musical' || (bool) preg_match('/concierto|orquesta|bandas sonoras|homenaje a/u', $title) => ['events-small-concerts', null],
            str_contains($title, 'musical') => ['events-stage', 'events-stage-musicals'],
            (bool) preg_match('/mon[oó]logo|stand-?up|comediante|humorista|c[oó]mico/u', $description) => ['events-stage', 'events-stage-comedy'],
            default => ['events-stage', 'events-stage-theater'],
        };
    }
}
