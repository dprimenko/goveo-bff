<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\JsonLd;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Wurlitzer Ballroom (wurlitzerballroom.com), sala de conciertos pequeña junto
 * a Gran Vía: punk, garage, power-pop, rock and roll.
 *
 * La agenda (`/agenda`) enlaza una ficha por concierto con la fecha en la
 * dirección (`/concierto/flippeur-2026-10-06`), así que sólo se abren las del
 * horizonte. Cada ficha trae su `MusicEvent` (nombre y hora) pero sin cartel ni
 * URL —por eso no sirve `JsonLdEventSource`—: el cartel es la imagen de la
 * ficha, los teloneros van bajo el título y las entradas en «Comprar entradas»
 * (Entradium, Fever…, lo que elija cada promotora).
 *
 * - ⚠️ El cartel es un enlace firmado de Supabase que caduca a las 24 h: vale
 *   porque la pasada lo descarga en el momento. Sin cartel propio la ficha usa
 *   la imagen genérica de la web, y ese concierto se queda fuera.
 * - La hora es la de puertas.
 */
final class WurlitzerSource implements EventSource
{
    private const SITE    = 'https://wurlitzerballroom.com';
    private const LISTING = self::SITE . '/agenda';
    private const NAME    = 'Wurlitzer Ballroom';
    private const LAT     = 40.4197191;
    private const LNG     = -3.7025532;
    private const ADDRESS = 'Calle de las Tres Cruces, 12, 28013 Madrid';

    /** Fichas que se abren: el cron pide 30 días, con margen. */
    private const HORIZON_DAYS = 40;

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'wurlitzer';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::LISTING);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la agenda del Wurlitzer');
        }

        $tz      = new \DateTimeZone('Europe/Madrid');
        $today   = new \DateTimeImmutable('today', $tz);
        $horizon = $today->modify(sprintf('+%d days', self::HORIZON_DAYS));

        preg_match_all('#/concierto/([a-z0-9-]+-(\d{4}-\d{2}-\d{2}))#', $html, $links, \PREG_SET_ORDER);
        $events = [];
        foreach ($links as [, $slug, $date]) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
            if (isset($events[$slug]) || $day === false || $day < $today || $day > $horizon) {
                continue;
            }
            $event = $this->concert($slug, $tz);
            if ($event !== null) {
                $events[$slug] = $event;
            }
        }

        return array_values($events);
    }

    /** La ficha ya se leyó en `fetch`. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: self::NAME,
            city: $this->city(),
            categorySlug: 'live-music',
            latitude: self::LAT,
            longitude: self::LNG,
            address: self::ADDRESS,
            website: self::SITE . '/',
        );
    }

    private function concert(string $slug, \DateTimeZone $tz): ?ScrapedEvent
    {
        $url  = self::SITE . '/concierto/' . $slug;
        $html = $this->web->get($url);
        if ($html === null) {
            return null;
        }

        $node  = JsonLd::events($html)[0] ?? null;
        $xp    = Html::xpath($html);
        $image = Html::attr($xp, '//meta[@property="og:image"]', 'content');
        if ($node === null || !is_string($node['startDate'] ?? null) || $image === null || !str_contains($image, '/carteles/')) {
            return null;
        }

        try {
            $start = (new \DateTimeImmutable($node['startDate']))->setTimezone($tz);
        } catch (\Exception) {
            return null;
        }

        $headliner = Html::text($xp, '//h1');
        $support   = Html::text($xp, '//h1/following-sibling::p[1]');
        $title     = Html::clean(trim($headliner . ($support !== null && str_starts_with($support, '+') ? ' ' . $support : '')), 200);
        if ($title === null) {
            return null;
        }

        $genres = [];
        foreach ($xp->query('//dl/following-sibling::div[1]/span') as $span) {
            $genres[] = trim($span->textContent);
        }
        $price   = Html::text($xp, '//dt[normalize-space()="Precio"]/following-sibling::dd[1]');
        $tickets = Html::attr($xp, '//a[normalize-space()="Comprar entradas"]', 'href');

        $description = implode(' ', array_filter([
            $genres !== [] ? ucfirst(implode(', ', $genres)) . '.' : null,
            'Puertas a las ' . $start->format('H:i') . '.',
            $price !== null ? 'Precio: ' . $price . ' €.' : null,
        ]));

        return new ScrapedEvent(
            source: $this->name(),
            externalId: $slug,
            title: $title,
            start: $start,
            end: null,
            city: $this->city(),
            venueName: self::NAME,
            latitude: self::LAT,
            longitude: self::LNG,
            link: $tickets ?? $url,
            linkAction: $tickets !== null ? 'buy' : 'info',
            description: Html::clean($description),
            imageUrl: $image,
            detailUrl: $url,
            venueAddress: self::ADDRESS,
            subcategory: 'events-small-concerts',
        );
    }
}
