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
 * Corral de la Morería, tablao de la calle Morería. No tiene un espectáculo
 * fijo: el elenco cambia cada pocos días, y su página de espectáculos publica
 * cada tanda («DEL 25 AL 27 DE SEPTIEMBRE») con sus artistas, su foto y el
 * enlace de compra, que es el que trae el año (`?fecha=2026-09-25`).
 *
 * Una tanda es un evento con su rango: son espectáculos distintos, como las
 * fichas de Cardamomo, y juntarlos todos dejaría un solo evento con el elenco de
 * la primera semana.
 *
 * La hora no está en la página: sale de la taquilla (la misma consulta que hace
 * su formulario al elegir día), que lista los pases de «ESPECTÁCULO».
 */
final class CorralDeLaMoreriaSource implements EventSource
{
    private const BASE    = 'https://www.corraldelamoreria.com';
    private const LISTING = self::BASE . '/espectaculo-flamenco/';
    private const TICKETS = self::BASE . '/flamenco-tickets-madrid/';
    private const LAT     = 40.4126689;
    private const LNG     = -3.7142336;
    private const ADDRESS = 'Calle de la Morería, 17, 28005 Madrid';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'corral-moreria';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::LISTING);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la programación de Corral de la Morería');
        }

        $xp     = Html::xpath($html);
        $passes = [];

        foreach ($xp->query('//*[' . Html::hasClass('show-parent') . ']') as $show) {
            $heading = Html::text($xp, './/h2', $show);
            $buy     = Html::attr($xp, './/a[' . Html::hasClass('show-parent-buy') . ']', 'href', $show);
            $style   = Html::attr($xp, './/*[' . Html::hasClass('show-parent-img') . ']', 'style', $show);
            $id      = $show instanceof \DOMElement ? $show->getAttribute('id') : '';
            if ($heading === null || $buy === null || $id === ''
                || !preg_match('/fecha=(\d{4}-\d{2}-\d{2})/', $buy, $f)) {
                continue;
            }

            $first = new \DateTimeImmutable($f[1], new \DateTimeZone('Europe/Madrid'));
            $last  = $this->lastDay($heading, $first);
            // Las tandas que ya terminaron siguen en la página unos días.
            if ($last < new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'))) {
                continue;
            }
            $time  = $this->showTime($first);
            $stars = $this->stars($xp, $show);

            // Un pase por noche, todos con el id de la tanda: `Shows` los junta
            // en uno que empieza en la próxima noche.
            for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
                $passes[] = new ScrapedEvent(
                    source: $this->name(),
                    externalId: $id,
                    title: $stars !== [] ? 'Corral de la Morería: ' . implode(' y ', $stars) : 'Espectáculo flamenco en Corral de la Morería',
                    start: $time !== null ? $day->setTime($time[0], $time[1]) : $day,
                    // Sin hora conocida, la noche entera.
                    end: $time !== null ? null : $day->setTime(23, 59),
                    city: $this->city(),
                    venueName: 'Corral de la Morería',
                    latitude: self::LAT,
                    longitude: self::LNG,
                    link: $buy,
                    linkAction: 'buy',
                    description: $this->cast($xp, $show),
                    imageUrl: $style !== null && preg_match('/url\(([^)]+)\)/', $style, $img) ? trim($img[1], '\'" ') : null,
                    detailUrl: self::LISTING,
                    venueAddress: self::ADDRESS,
                    subcategory: 'events-flamenco',
                    subtype: 'events-flamenco-tablao',
                );
            }
        }

        return Shows::group($passes);
    }

    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: 'Corral de la Morería',
            city: $this->city(),
            categorySlug: 'culture-business',
            latitude: self::LAT,
            longitude: self::LNG,
            address: self::ADDRESS,
            website: self::BASE . '/',
        );
    }

    /**
     * El último día de la tanda, del título: «DEL 21 AL 24 DE SEPTIEMBRE»,
     * «DEL 28 DE SEPTIEMBRE AL 02 DE OCTUBRE». Si no se entiende, sólo el
     * primero.
     */
    private function lastDay(string $heading, \DateTimeImmutable $first): \DateTimeImmutable
    {
        if (!preg_match('/\bAL\s+(\d{1,2})(?:\s+DE)?\s+(\p{L}+)/iu', $heading, $m)
            || ($month = SpanishDate::month($m[2])) === null) {
            return $first;
        }

        $year = (int) $first->format('Y') + ($month < (int) $first->format('n') ? 1 : 0);
        if (!checkdate($month, (int) $m[1], $year)) {
            return $first;
        }

        $last = $first->setDate($year, $month, (int) $m[1]);

        // Una tanda de más de un mes es un título mal leído: mejor un día que
        // un evento que no se acaba.
        return $last >= $first && $last <= $first->modify('+31 days') ? $last : $first;
    }

    /**
     * El primer pase de espectáculo del día, según la taquilla. Los pases con
     * cena empiezan antes por la cena, no por el baile.
     *
     * @return array{0: int, 1: int}|null
     */
    private function showTime(\DateTimeImmutable $day): ?array
    {
        $html = $this->web->get(self::TICKETS . '?action=comprobar_productos&idioma=es&fecha=' . $day->format('Y-m-d'));
        if ($html === null) {
            return null;
        }

        $times = [];
        // `modificar_producto('E1','49.95','19:30', 'ESPECTÁCULO', …)`
        if (preg_match_all("/modificar_producto\\('E\\w*',\\s*'[^']*',\\s*'\\s*(\\d{1,2}):(\\d{2})'/", $html, $m, \PREG_SET_ORDER)) {
            foreach ($m as $t) {
                $times[] = [(int) $t[1], (int) $t[2]];
            }
        }
        sort($times);

        return $times[0] ?? null;
    }

    /**
     * Las figuras del cartel —«Bailaora Estrella», «Bailaor Estrella»—, que son
     * lo que distingue una tanda de otra.
     *
     * @return list<string>
     */
    private function stars(\DOMXPath $xp, \DOMNode $show): array
    {
        $stars = [];
        foreach ($xp->query('.//li[span[contains(., "Estrella")]]/strong', $show) as $strong) {
            $name = mb_convert_case(mb_strtolower(trim($strong->textContent)), \MB_CASE_TITLE);
            if ($name !== '') {
                $stars[] = $name;
            }
        }

        return $stars;
    }

    /** El elenco entero y el aviso de cambios, como descripción. */
    private function cast(\DOMXPath $xp, \DOMNode $show): ?string
    {
        $lines = [];
        foreach ($xp->query('.//li', $show) as $li) {
            $lines[] = trim((string) preg_replace('/\s+/u', ' ', $li->textContent));
        }
        $notice = Html::text($xp, './/*[' . Html::hasClass('texto-aviso-camios') . ']', $show);

        return Html::clean(implode('. ', array_filter($lines)) . '.' . ($notice !== null ? ' ' . $notice : ''));
    }
}
