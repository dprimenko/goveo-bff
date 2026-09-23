<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Sala Clamores (salaclamores.es, hecha en Webflow): el calendario trae el
 * cartel en la propia tarjeta, así que no hace falta abrir las fichas para la
 * imagen — sólo para la descripción.
 *
 * Pagina con `?e5273e04_page=N`. Ese prefijo es el id de la colección en
 * Webflow: si rehacen la página cambia, y entonces sólo se lee la primera.
 */
final class ClamoresSource implements EventSource
{
    private const BASE      = 'https://salaclamores.es';
    private const LISTING   = self::BASE . '/calendario';
    private const MAX_PAGES = 5;

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'clamores';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $url  = self::LISTING;
        $seen = [];

        for ($page = 1; $page <= self::MAX_PAGES && $url !== null; ++$page) {
            $html = $this->web->get($url);
            if ($html === null) {
                if ($page === 1) {
                    throw new \RuntimeException('No se pudo descargar el calendario de Clamores');
                }
                break;
            }

            $xp = Html::xpath($html);

            foreach ($xp->query('//a[' . Html::hasClass('card-blog-post-list') . ']') as $card) {
                $href  = $card instanceof \DOMElement ? $card->getAttribute('href') : '';
                $title = Html::text($xp, './/h2', $card);
                $day   = Html::text($xp, './/*[' . Html::hasClass('date-component-calendar-2') . ']', $card);
                $month = SpanishDate::month((string) Html::text($xp, './/*[' . Html::hasClass('date-component-calendar-3') . ']', $card));
                $time  = Html::text($xp, './/*[' . Html::hasClass('date-component-calendar4') . ']', $card);
                $img   = Html::attr($xp, './/img', 'src', $card);

                if ($href === '' || $title === null || !ctype_digit((string) $day) || $month === null) {
                    continue;
                }

                $start = SpanishDate::build((int) $day, $month, $time);
                $slug  = basename(trim((string) parse_url($href, \PHP_URL_PATH), '/'));
                if ($start === null || isset($seen[$slug])) {
                    continue;
                }
                $seen[$slug] = true;

                $detail = Html::absolute($href, self::BASE);

                yield new ScrapedEvent(
                    source: $this->name(),
                    externalId: $slug,
                    title: $title,
                    start: $start,
                    end: null,
                    city: $this->city(),
                    venueName: 'Clamores',
                    latitude: 40.4310342,
                    longitude: -3.7008764,
                    link: $detail,
                    linkAction: 'buy',
                    imageUrl: $img,
                    detailUrl: $detail,
                );
            }

            $next = Html::attr($xp, '//a[' . Html::hasClass('w-pagination-next') . ']', 'href');
            $url  = $next !== null ? self::LISTING . (str_starts_with($next, '?') ? $next : '?' . ltrim((string) parse_url($next, \PHP_URL_QUERY), '?')) : null;
        }
    }

    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        $xp = Html::xpath($html);

        return $event->withDetails(
            null,
            Html::clean(Html::attr($xp, '//meta[@name="description"]', 'content')),
        );
    }
}
