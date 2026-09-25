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
 * Compañía Nacional de Teatro Clásico (teatroclasico.inaem.gob.es), un
 * WordPress. En Madrid actúa en el Teatro de la Comedia, con dos salas: la
 * Principal y la Tirso de Molina.
 *
 * Tiene la API de «The Events Calendar», pero la dejaron de usar en 2019 y
 * está vacía. La cartelera son dos páginas de temporada, una por sala, con una
 * tarjeta por espectáculo: cartel, rango de fechas y enlace de entradas. Su
 * dirección cambia cada temporada (`/sala-principal-temporada-26-27/`), así que
 * se buscan por el menú de la portada, que es lo que ellos actualizan.
 *
 * Sin hora: el rango sólo dice días, y el horario —cuando lo ponen— es texto
 * libre («De martes a domingo a las 20:00h»). Cada espectáculo dura su rango
 * entero. Lo que hacen de gira, fuera de sede y las actividades no se lee.
 */
final class TeatroClasicoSource implements EventSource
{
    private const HOME = 'https://teatroclasico.inaem.gob.es';

    /** Los rótulos del menú que llevan a la cartelera de cada sala. */
    private const ROOMS = ['sala principal', 'sala tirso de molina'];

    private const VENUE = [
        'name'    => 'Teatro de la Comedia',
        'lat'     => 40.4155336,
        'lng'     => -3.7003923,
        'address' => 'Calle del Príncipe, 14, 28012 Madrid',
    ];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'cntc';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $home = $this->web->get(self::HOME . '/');
        if ($home === null) {
            throw new \RuntimeException('No se pudo descargar la portada de la Compañía Nacional de Teatro Clásico');
        }

        $pages = [];
        $xp    = Html::xpath($home);
        foreach ($xp->query('//a[@href]') as $a) {
            if (in_array(mb_strtolower(trim($a->textContent)), self::ROOMS, true)) {
                $pages[] = (string) Html::absolute($a->getAttribute('href'), self::HOME);
            }
        }
        $pages = array_values(array_unique($pages));
        if ($pages === []) {
            throw new \RuntimeException('La portada de la CNTC ya no enlaza la cartelera de sus salas');
        }

        $shows = [];
        foreach ($pages as $url) {
            $html = $this->web->get($url);
            if ($html === null) {
                continue;
            }

            $xp = Html::xpath($html);
            foreach ($xp->query('//article[' . Html::hasClass('post-grid') . ']') as $card) {
                $title   = Html::clean(Html::text($xp, './/h3[' . Html::hasClass('post-grid-content-title') . ']', $card), 200);
                $page    = Html::attr($xp, './/h3//a', 'href', $card);
                $preview = (string) Html::text($xp, './/div[' . Html::hasClass('post-preview') . ']', $card);
                if ($title === null || $page === null || ($range = $this->range($preview)) === null) {
                    continue;
                }

                // La tarjeta trae el cartel recortado (`…-600x400.jpg`); sin el
                // sufijo es el original, el mismo del `og:image`.
                $poster  = Html::attr($xp, './a/img', 'src', $card);
                $tickets = Html::attr($xp, './/div[' . Html::hasClass('post-link') . ']//a', 'href', $card);
                $tickets = $tickets !== null && preg_match('#^https?://#', $tickets) ? $tickets : null;

                $shows[] = new ScrapedEvent(
                    source: $this->name(),
                    externalId: basename(trim((string) parse_url($page, \PHP_URL_PATH), '/')),
                    title: $title,
                    start: $range[0],
                    end: $range[1],
                    city: $this->city(),
                    venueName: self::VENUE['name'],
                    latitude: self::VENUE['lat'],
                    longitude: self::VENUE['lng'],
                    link: $tickets ?? $page,
                    linkAction: $tickets !== null ? 'buy' : 'info',
                    // Autor, dirección, fechas y sala; la sinopsis sale de la ficha.
                    description: Html::clean($preview, 300),
                    imageUrl: $poster !== null ? Html::absolute((string) preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $poster), self::HOME) : null,
                    detailUrl: $page,
                    venueAddress: self::VENUE['address'],
                    subcategory: 'events-stage',
                    // Algún concierto (el de Navidad) se queda en Escena, como la
                    // música clásica del Auditorio y el Real.
                    subtype: str_contains(mb_strtolower($title), 'concierto') ? null : 'events-stage-theater',
                );
            }
        }

        // Cada espectáculo sale una vez, con su rango: `Shows` sólo quita lo
        // que ya terminó y lo que saliera repetido en las dos salas.
        return Shows::group($shows);
    }

    /** La sinopsis: el primer párrafo largo de la pestaña «Introducción». */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        $xp = Html::xpath($html);
        foreach ($xp->query('(//div[' . Html::hasClass('tab-pane') . '])[1]//p') as $p) {
            $text = Html::clean($p->textContent, 400);
            if ($text !== null && mb_strlen($text) >= 80) {
                return $event->withDetails(null, $text);
            }
        }

        return $event;
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: self::VENUE['name'],
            city: $this->city(),
            categorySlug: 'culture-business',
            latitude: self::VENUE['lat'],
            longitude: self::VENUE['lng'],
            address: self::VENUE['address'],
            website: self::HOME . '/',
        );
    }

    /**
     * «24 SEP – 22 NOV 2026», «5 – 22 NOV», «18 DIC 2026 – 3 ENE 2027»,
     * «21 ENE -21 FEB 2027» o un día suelto. Sin año, lo deduce `SpanishDate`
     * por el mes del final; el principio es de ese año o del anterior.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    private function range(string $text): ?array
    {
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $mon  = '([A-Za-zÁÉÍÓÚáéíóú]{3,})';
        if (preg_match("/(\\d{1,2})\\s*(?:{$mon}\\.?\\s*(\\d{4})?)?\\s*[–—-]\\s*(\\d{1,2})\\s+{$mon}\\.?\\s*(\\d{4})?/u", $text, $m)) {
            [$d1, $m1, $y1, $d2, $m2, $y2] = [(int) $m[1], $m[2] ?? '', $m[3] ?? '', (int) $m[4], $m[5], $m[6] ?? ''];
        } elseif (preg_match("/(\\d{1,2})\\s+{$mon}\\.?\\s*(\\d{4})?/u", $text, $m)) {
            [$d1, $m1, $y1, $d2, $m2, $y2] = [(int) $m[1], '', '', (int) $m[1], $m[2], $m[3] ?? ''];
        } else {
            return null;
        }

        $endMonth = SpanishDate::month($m2);
        $month1   = $m1 !== '' ? SpanishDate::month($m1) : $endMonth;
        if ($endMonth === null || $month1 === null) {
            return null;
        }

        $end = $y2 !== ''
            ? $this->date($d2, $endMonth, (int) $y2)
            : SpanishDate::build($d2, $endMonth, null);
        if ($end === null) {
            return null;
        }

        $year  = $y1 !== '' ? (int) $y1 : (int) $end->format('Y') - ($month1 > $endMonth ? 1 : 0);
        $start = $this->date($d1, $month1, $year);

        return $start !== null && $start <= $end ? [$start, $end->setTime(23, 59)] : null;
    }

    private function date(int $day, int $month, int $year): ?\DateTimeImmutable
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid')))->setDate($year, $month, $day);
    }
}
