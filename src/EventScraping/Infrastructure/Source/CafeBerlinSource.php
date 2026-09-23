<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Café Berlín (berlincafe.es): toda la programación hasta fin de año en una
 * sola página, con día, hora, precio y enlace de entradas.
 *
 * La foto y la descripción sólo están en la ficha, y la ficha **no tiene
 * `og:image`**: la foto es la primera de la galería (`.project-gallery`).
 *
 * El mismo programa se repite en fechas distintas (la jam de cada jueves), así
 * que el identificador lleva la fecha.
 */
final class CafeBerlinSource implements EventSource
{
    private const LISTING = 'https://berlincafe.es/programas/';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'berlin';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::LISTING);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la programación de Café Berlín');
        }

        $xp   = Html::xpath($html);
        $seen = [];

        foreach ($xp->query('//article[' . Html::hasClass('programas-lista-programa') . ']') as $card) {
            $detail = Html::attr($xp, './/a[.//h2]', 'href', $card);
            $title  = Html::text($xp, './/h2', $card);
            $day    = Html::text($xp, './/*[' . Html::hasClass('evento-fecha-dia') . ']', $card);
            $month  = SpanishDate::month((string) Html::text($xp, './/*[' . Html::hasClass('evento-fecha-mes') . ']', $card));
            $info   = (string) Html::text($xp, './/h5', $card);

            if ($detail === null || $title === null || !ctype_digit((string) $day) || $month === null) {
                continue;
            }

            $start = SpanishDate::build((int) $day, $month, $info);
            if ($start === null) {
                continue;
            }

            $slug = trim((string) parse_url($detail, \PHP_URL_PATH), '/');
            $slug = basename($slug);
            $id   = $slug . '@' . $start->format('Y-m-d');
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $tickets = Html::attr($xp, './/a[' . Html::hasClass('btn-entradas') . ']', 'href', $card);

            yield new ScrapedEvent(
                source: $this->name(),
                externalId: $id,
                title: $title,
                start: $start,
                end: null,
                city: $this->city(),
                venueName: 'Café Berlín',
                latitude: 40.4195885,
                longitude: -3.7079430,
                link: $tickets ?? $detail,
                linkAction: $tickets !== null ? 'buy' : 'info',
                detailUrl: $detail,
            );
        }
    }

    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        $xp = Html::xpath($html);

        return $event->withDetails(
            Html::attr($xp, '//*[' . Html::hasClass('project-gallery') . ']//img', 'src'),
            Html::clean(Html::text($xp, '//*[' . Html::hasClass('entry-description') . ']//p')),
        );
    }
}
