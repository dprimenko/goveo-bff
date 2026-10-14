<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\ICal;

/**
 * Actividad infantil del Ayuntamiento de Boadilla del Monte
 * (`/tipo-evento/actividad-infantil`): cuentacuentos en el teatro, bebecuentos
 * en la biblioteca…
 *
 * Drupal 7, sin datos estructurados. El listado ya es sólo infantil, pero en el
 * lateral cuela otros eventos (fiestas de 2022, inscripciones de tenis), así que
 * de cada ficha se comprueba que su «Tipo de evento» sea «Actividad infantil».
 * La hora sale del iCal de la ficha (`/ical/{nid}/export.ics`) y el sitio, del
 * texto: no hay campo para él.
 *
 * El cartel de la ficha es una miniatura (`/styles/…/public/`); quitando el
 * estilo se pide el original.
 */
final class BoadillaSource extends MunicipalAgendaSource
{
    private const SITE    = 'https://ayuntamientoboadilladelmonte.org';
    private const LISTING = self::SITE . '/tipo-evento/actividad-infantil';
    private const MAX     = 30;

    /** Palabra del texto => sitio. El teatro y la biblioteca comparten edificio. */
    private const PLACES = [
        'ortega y gasset' => ['Biblioteca José Ortega y Gasset', 'Av. Isabel de Farnesio, 33, 28660 Boadilla del Monte, Madrid', 40.3992176, -3.8837769],
        'biblioteca'      => ['Biblioteca Municipal Princesa Doña Leonor', 'C. de los Mártires, 1, 28660 Boadilla del Monte, Madrid', 40.40649, -3.87574],
        'teatro'          => ['Teatro Municipal Princesa Doña Leonor', 'C. de los Mártires, 1, 28660 Boadilla del Monte, Madrid', 40.40649, -3.87574],
    ];

    public function name(): string
    {
        return 'boadilla';
    }

    protected function municipality(): string
    {
        return 'Boadilla del Monte';
    }

    protected function center(): array
    {
        return [40.4077212, -3.8792454, 'C. Juan Carlos I, 42, 28660 Boadilla del Monte, Madrid'];
    }

    protected function places(): array
    {
        $places = [];
        foreach (self::PLACES as $place) {
            $places[$this->slug($place[0])] = $place;
        }

        return $places;
    }

    protected function kidsOnly(): bool
    {
        return false;
    }

    protected function raw(): iterable
    {
        $html = $this->web->get(self::LISTING);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la actividad infantil de Boadilla');
        }

        $links = [];
        foreach (Html::xpath($html)->query('//a[contains(@href, "/boadilla-actualidad/agenda/")]/@href') as $href) {
            $url = Html::absolute(strtok((string) $href->nodeValue, '?'), self::SITE);
            if ($url !== null && preg_match('#/agenda/[a-z0-9-]+$#', $url)) {
                $links[$url] = true;
            }
        }

        $tz = new \DateTimeZone('Europe/Madrid');
        foreach (array_slice(array_keys($links), 0, self::MAX) as $url) {
            if (($page = $this->web->get($url)) !== null && ($event = $this->event($url, $page, $tz)) !== null) {
                yield $event;
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function event(string $url, string $page, \DateTimeZone $tz): ?array
    {
        $xp   = Html::xpath($page);
        $type = Html::text($xp, '//div[' . Html::hasClass('field-name-field-evento-tipo') . ']');
        $ics  = Html::attr($xp, '//a[' . Html::hasClass('ical-link') . ']', 'href');
        if ($type === null || !str_contains(mb_strtolower($type), 'actividad infantil') || $ics === null) {
            return null;
        }

        $calendar = $this->web->get((string) Html::absolute($ics, self::SITE));
        $vevent   = $calendar !== null ? (ICal::events($calendar)[0] ?? null) : null;
        if ($vevent === null) {
            return null;
        }

        $body  = (string) Html::text($xp, '//div[' . Html::hasClass('field-name-body') . ']');
        $image = Html::attr($xp, '//div[' . Html::hasClass('field-name-field-imagen') . ']//img', 'src');

        return [
            'id'          => basename($url),
            'title'       => $vevent['SUMMARY'] ?? Html::text($xp, '//h1'),
            'start'       => ICal::date($vevent['DTSTART'] ?? null, $tz),
            'end'         => ICal::date($vevent['DTEND'] ?? null, $tz),
            'place'       => $this->placeIn($body),
            'description' => $body,
            'image'       => $image !== null ? (string) preg_replace(['#/styles/[^/]+/public/#', '#\?.*$#'], ['/', ''], $image) : null,
            'url'         => $url,
        ];
    }

    /** El sitio por lo que dice el texto («Teatro Municipal Princesa Doña Leonor, c/ Mártires, 1»). */
    private function placeIn(string $body): string
    {
        $body = mb_strtolower($body);
        foreach (self::PLACES as $word => $place) {
            if (str_contains($body, $word)) {
                return $place[0];
            }
        }

        return '';
    }
}
