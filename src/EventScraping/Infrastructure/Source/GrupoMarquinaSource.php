<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Grupo Marquina (grupomarquina.es): la cartelera de sus dos teatros, el
 * Marquina y el Príncipe Gran Vía. La web del Excel (teatromarquina.es) ya no
 * es la suya.
 *
 * La cartelera trae título, rango («08/09/2026 - 18/10/2026») y teatro, pero
 * **no la hora ni los días de función**: los sabe la venta de entradas, que
 * está detrás del desafío de Cloudflare y no se lee. Cada espectáculo dura su
 * rango entero, como en el Calderón.
 *
 * El género («Comedia», «Musical»…) y el cartel están en la ficha, y hacen falta
 * antes de filtrar —el tipo no se cambia en `enrich`—, así que se abre cada
 * ficha al leer la cartelera; son media docena. Los carteles de la web van
 * incrustados en el HTML (`data:`), y lo que se usa es la imagen para redes.
 */
final class GrupoMarquinaSource implements EventSource
{
    private const HOME    = 'https://www.grupomarquina.es';
    private const LISTING = self::HOME . '/espectaculos';

    private const VENUES = [
        'teatro marquina' => [
            'name'    => 'Teatro Marquina',
            'lat'     => 40.4219662,
            'lng'     => -3.6937277,
            'address' => 'Calle de Prim, 11, 28004 Madrid',
            'website' => self::HOME . '/teatros/teatro-marquina',
        ],
        'teatro príncipe gran vía' => [
            'name'    => 'Teatro Príncipe Gran Vía',
            'lat'     => 40.4194965,
            'lng'     => -3.7026678,
            'address' => 'Calle de las Tres Cruces, 8, 28013 Madrid',
            'website' => self::HOME . '/teatros/teatro-principe-gran-via',
        ],
    ];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'grupo-marquina';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::LISTING);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la cartelera de Grupo Marquina');
        }

        $xp = Html::xpath($html);
        $tz = new \DateTimeZone('Europe/Madrid');

        foreach ($xp->query('//div[' . Html::hasClass('col-lg-2') . ' and .//span[' . Html::hasClass('glyphicon-calendar') . ']]') as $card) {
            $href  = Html::attr($xp, './/a[' . Html::hasClass('read-more') . ']', 'href', $card);
            $dates = (string) Html::text($xp, './/p[span[' . Html::hasClass('glyphicon-calendar') . ']]', $card);
            $venue = self::VENUES[mb_strtolower((string) Html::text($xp, './/center/p[last()]', $card))] ?? null;

            // La tarjeta regalo sale como un espectáculo más, enlazada a la venta.
            if ($href === null || !str_starts_with($href, '/espectaculos/') || $venue === null
                || !preg_match_all('#(\d{2})/(\d{2})/(\d{4})#', $dates, $m, \PREG_SET_ORDER)) {
                continue;
            }

            $page = self::HOME . $href;
            // Con los carteles incrustados, una ficha pasa de 6 MB.
            if (($detail = $this->web->get($page, 12 * 1024 * 1024)) === null) {
                continue;
            }
            $dx    = Html::xpath($detail);
            $title = Html::clean(Html::text($dx, '//div[@id="content_hero"]//h1', null) ?? Html::attr($xp, './/img', 'alt', $card), 200);
            $image = Html::attr($dx, '//meta[@property="og:image"]', 'content');
            if ($title === null || $image === null) {
                continue;
            }

            $first   = \DateTimeImmutable::createFromFormat('!d/m/Y', $m[0][0], $tz);
            $last    = \DateTimeImmutable::createFromFormat('!d/m/Y', ($m[1] ?? $m[0])[0], $tz);
            $tickets = Html::attr($dx, '//div[@id="content_hero"]//a[@target="entradas"]', 'href');
            $genre   = mb_strtolower((string) Html::text($dx, '//div[@id="content_hero"]//span[' . Html::hasClass('title') . ']'));
            if ($first === false || $last === false) {
                continue;
            }

            yield new ScrapedEvent(
                source: $this->name(),
                externalId: basename($href),
                title: $title,
                start: $first,
                end: $last->setTime(23, 59),
                city: $this->city(),
                venueName: $venue['name'],
                latitude: $venue['lat'],
                longitude: $venue['lng'],
                link: $tickets ?? $page,
                linkAction: $tickets !== null ? 'buy' : 'info',
                description: Html::clean(Html::text($dx, '//h2[normalize-space() = "Sinopsis"]/following-sibling::div[1]'), 400),
                imageUrl: $this->encoded((string) Html::absolute($image, self::HOME)),
                detailUrl: $page,
                venueAddress: $venue['address'],
                subcategory: 'events-stage',
                subtype: $this->subtype($genre, mb_strtolower($title)),
            );
        }
    }

    /** La cartelera y la ficha ya lo traen todo. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        $venue = self::VENUES[mb_strtolower($event->venueName)] ?? null;
        if ($venue === null) {
            return null;
        }

        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue-' . basename($venue['website']),
            name: $venue['name'],
            city: $this->city(),
            categorySlug: 'culture-business',
            latitude: $venue['lat'],
            longitude: $venue['lng'],
            address: $venue['address'],
            website: $venue['website'],
        );
    }

    /**
     * Los nombres de fichero llevan espacios y tildes («PRÍNCIPE WIZ
     * 1200X628.png») y la URL de la ficha los trae tal cual.
     */
    private function encoded(string $url): string
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        $safe = implode('/', array_map(fn (string $s) => rawurlencode(rawurldecode($s)), explode('/', $path)));

        return str_replace($path, $safe, $url);
    }

    private function subtype(string $genre, string $title): string
    {
        return match (true) {
            str_contains($genre . ' ' . $title, 'musical') => 'events-stage-musicals',
            (bool) preg_match('/comedia|humor|mon[oó]logo/u', $genre . ' ' . $title) => 'events-stage-comedy',
            str_contains($genre, 'magia')  => 'events-stage-magic',
            str_contains($genre, 'danza')  => 'events-stage-dance',
            default                        => 'events-stage-theater',
        };
    }
}
