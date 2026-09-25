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
 * Teatro Infanta Isabel (teatroinfantaisabel.es): «En cartelera» y
 * «Próximamente» enlazan la ficha de cada obra (`/obra/{slug}/`), un WordPress
 * con Elementor sin datos estructurados ni nada en su API. La ficha tiene dos
 * cajas **escritas a mano**, «Fecha» y «Horario», y de ahí salen las fechas:
 *
 * - **Funciones sueltas** en el horario («Sábado 28 de noviembre del 2026 a
 *   las 22:30», una por línea): una por función, con su hora, y `Shows` las
 *   junta. Hace falta más de una: con una sola, suele ser la excepción de una
 *   temporada («el domingo 20 la función será a las 18:00»).
 * - **Temporada** («A partir del 18 de septiembre del 2026», «A partir del 06
 *   de noviembre al 29 de noviembre»): el rango, con los días de función que
 *   nombre el horario («De martes a sábados a las 19:30»). Sin fin anunciado,
 *   hoy + 30 días (`OPEN_RUN`) y cada pasada lo alarga, como en La Latina.
 * - **Días en la fecha** («3, 4, 10 y 11 de abril del 2027», «Jueves 5 de
 *   noviembre a las 21:00»): cada uno, con la hora si sólo hay una.
 *
 * Lo que no dice qué día («viernes de diciembre a las 23:00») se salta hasta
 * que lo pongan. La venta es de Onebox: sólo se enlaza.
 */
final class InfantaIsabelSource implements EventSource
{
    private const HOME     = 'https://www.teatroinfantaisabel.es';
    private const LISTINGS = [
        self::HOME . '/espectaculos/en-cartelera/',
        self::HOME . '/espectaculos/proximamente/',
    ];

    /** Hasta cuándo se da por en cartel lo que sólo dice «A partir del…». */
    private const OPEN_RUN = '+30 days';

    private const VENUE = [
        'name'    => 'Teatro Infanta Isabel',
        'lat'     => 40.4222447,
        'lng'     => -3.69553,
        'address' => 'Calle del Barquillo, 24, 28004 Madrid',
    ];

    private const WEEKDAYS = [
        'lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7,
    ];

    /** «28 de noviembre del 2026», «06 de marzo 27», «19 de diciembre», y la hora que le siga. */
    private const DATED = '/\b(\d{1,2})\s+de\s+([a-zé]+)(?:\s+del?)?(?:\s+(\d{4}|\d{2})\b(?![:.]\d))?(?:[^|\d]*?\ba\s+las\s+(\d{1,2})[:.](\d{2}))?/u';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'infanta-isabel';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $pages = [];
        foreach (self::LISTINGS as $i => $listing) {
            $html = $this->web->get($listing);
            if ($html === null) {
                if ($i === 0) {
                    throw new \RuntimeException('No se pudo descargar la cartelera del Teatro Infanta Isabel');
                }
                continue;
            }
            foreach (Html::xpath($html)->query('//a[contains(@href, "/obra/")]') as $a) {
                $href = $a instanceof \DOMElement ? Html::absolute($a->getAttribute('href'), self::HOME) : null;
                if ($href !== null && preg_match('#^https://www\.teatroinfantaisabel\.es/obra/[^/]+/?$#', $href)) {
                    $pages[$href] = true;
                }
            }
        }

        $performances = [];
        foreach (array_keys($pages) as $page) {
            foreach ($this->play($page) as $performance) {
                $performances[] = $performance;
            }
        }

        return Shows::group($performances);
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
     * Las funciones de una obra, del mismo `externalId` para que `Shows` las
     * junte.
     *
     * @return list<ScrapedEvent>
     */
    private function play(string $page): array
    {
        $html = $this->web->get($page);
        if ($html === null) {
            return [];
        }

        // Cada función del horario va en su línea: el `|` impide que la hora de
        // una se pegue a la fecha de la siguiente.
        $xp       = Html::xpath((string) preg_replace('#<br\s*/?>#i', ' | ', $html));
        $title    = Html::clean(Html::text($xp, '//h1'), 200);
        $image    = Html::attr($xp, '//meta[@property="og:image"]', 'content');
        $date     = $this->box($xp, 'Fecha');
        $schedule = $this->box($xp, 'Horario');
        if ($title === null || $image === null || ($date === '' && $schedule === '')) {
            return [];
        }

        // Sin la consulta: lleva el rastreo de Google Analytics (`_gl`) de quien
        // editó la web.
        $tickets     = Html::attr($xp, '//a[.//span[normalize-space() = "Comprar Entradas"]]', 'href');
        $tickets     = $tickets !== null ? strtok($tickets, '?') : null;
        $description = Html::clean(Html::text($xp, '//h2[normalize-space() = "El espectáculo"]/following::div[' . Html::hasClass('elementor-widget-text-editor') . '][1]'), 400);
        [$subcategory, $subtype] = $this->classify(mb_strtolower($title . ' ' . $description));

        $make = fn (\DateTimeImmutable $start, ?\DateTimeImmutable $end, ?array $weekdays = null) => new ScrapedEvent(
            source: $this->name(),
            externalId: basename($page),
            title: $title,
            start: $start,
            end: $end,
            city: $this->city(),
            venueName: self::VENUE['name'],
            latitude: self::VENUE['lat'],
            longitude: self::VENUE['lng'],
            link: $tickets ?? $page,
            linkAction: $tickets !== null ? 'buy' : 'info',
            description: $description,
            imageUrl: $image,
            detailUrl: $page,
            weekdays: $weekdays,
            venueAddress: self::VENUE['address'],
            subcategory: $subcategory,
            subtype: $subtype,
        );

        // Funciones sueltas en el horario.
        $listed = $this->dated($schedule);
        if (count($listed) >= 2) {
            return array_map(fn (\DateTimeImmutable $d) => $make($d, $this->endOf($d)), $listed);
        }

        $days = $this->dated($date);
        if ($days === []) {
            return [];
        }

        // Temporada: «A partir del…», con o sin «al…».
        if (preg_match('/^\s*(a partir|desde)/iu', $date)) {
            $first = $days[0]->setTime(0, 0);
            if (count($days) >= 2) {
                $last = $days[count($days) - 1];
            } else {
                $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));
                $last  = max($first, $today)->modify(self::OPEN_RUN);
            }

            return [$make($first, $last->setTime(23, 59), $this->weekdays($schedule))];
        }

        // Días sueltos en la fecha: con la hora del horario si es una sola.
        $times = $this->times($date . ' ' . $schedule);

        return array_map(function (\DateTimeImmutable $d) use ($make, $times) {
            if ($d->format('H:i') === '00:00' && count($times) === 1) {
                $d = $d->setTime(...$times[0]);
            }

            return $make($d, $this->endOf($d));
        }, $days);
    }

    /** Un pase con hora dura hasta que acaba; sin ella, el día entero. */
    private function endOf(\DateTimeImmutable $d): ?\DateTimeImmutable
    {
        return $d->format('H:i') === '00:00' ? $d->setTime(23, 59) : null;
    }

    /** El texto de la caja «Fecha» u «Horario» de la ficha, sin su título. */
    private function box(\DOMXPath $xp, string $label): string
    {
        $box = $xp->query('//div[' . Html::hasClass('elementor-icon-box-content') . '][normalize-space(h2) = "' . $label . '"]')?->item(0);
        if ($box === null) {
            return '';
        }
        $text = '';
        foreach ($box->childNodes as $child) {
            if ($child->nodeName !== 'h2') {
                $text .= ' ' . $child->textContent;
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Las fechas del texto, en orden. Los días sin mes toman el del siguiente
     * que lo tenga («3, 4, 10 y 11 de abril»); sin año, el que deduce
     * `SpanishDate`; «27» es 2027.
     *
     * @return list<\DateTimeImmutable>
     */
    private function dated(string $text): array
    {
        $text = mb_strtolower($text);
        $tz   = new \DateTimeZone('Europe/Madrid');
        $out  = [];

        if (!preg_match_all(self::DATED, $text, $m, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($m as $match) {
            $month = SpanishDate::month($match[2][0]);
            if ($month === null) {
                continue;
            }
            $year = isset($match[3]) && $match[3][0] !== '' ? (int) $match[3][0] : null;
            $year = $year !== null && $year < 100 ? 2000 + $year : $year;
            $time = isset($match[4]) && $match[4][0] !== '' ? $match[4][0] . ':' . $match[5][0] : null;

            // Los días sueltos de delante: «3, 4, 10 y» antes de «11 de abril».
            $before = mb_substr(substr($text, 0, $match[0][1]), -40);
            $extra  = preg_match('/((?:\d{1,2}\s*(?:,|y)\s*)+)$/u', $before, $e) ? array_map('intval', preg_split('/\D+/', $e[1], -1, \PREG_SPLIT_NO_EMPTY)) : [];

            foreach ([...$extra, (int) $match[1][0]] as $day) {
                $date = $year !== null
                    ? (checkdate($month, $day, $year) ? (new \DateTimeImmutable('today', $tz))->setDate($year, $month, $day) : null)
                    : SpanishDate::build($day, $month, null);
                if ($date !== null && $time !== null) {
                    [$h, $i] = array_map('intval', explode(':', $time));
                    $date    = $date->setTime($h, $i);
                }
                if ($date !== null) {
                    $out[] = $date;
                }
            }
        }

        return $out;
    }

    /** @return list<array{0: int, 1: int}> las horas distintas del texto */
    private function times(string $text): array
    {
        preg_match_all('/\b(\d{1,2})[:.](\d{2})\b/', $text, $m, \PREG_SET_ORDER);
        $times = [];
        foreach ($m as $t) {
            $times[$t[1] . ':' . $t[2]] = [(int) $t[1], (int) $t[2]];
        }

        return array_values($times);
    }

    /**
     * Los días de función que nombra el horario: «de martes a sábados»,
     * «jueves y viernes», «domingos». Nulo si no nombra ninguno.
     *
     * @return list<int>|null
     */
    private function weekdays(string $schedule): ?array
    {
        $text  = strtr(mb_strtolower($schedule), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        $names = implode('|', array_keys(self::WEEKDAYS));
        $days  = [];

        if (preg_match_all('/\b(' . $names . ')s?\s+a\s+(' . $names . ')s?\b/u', $text, $ranges, \PREG_SET_ORDER)) {
            foreach ($ranges as $r) {
                for ($d = self::WEEKDAYS[$r[1]]; ; $d = $d % 7 + 1) {
                    $days[$d] = true;
                    if ($d === self::WEEKDAYS[$r[2]]) {
                        break;
                    }
                }
            }
        }
        if (preg_match_all('/\b(' . $names . ')s?\b/u', $text, $single)) {
            foreach ($single[1] as $name) {
                $days[self::WEEKDAYS[$name]] = true;
            }
        }
        if ($days === []) {
            return null;
        }
        $days = array_keys($days);
        sort($days);

        return $days;
    }

    /** @return array{0: string, 1: ?string} */
    private function classify(string $text): array
    {
        return match (true) {
            (bool) preg_match('/\bconcierto\b/u', $text) => ['events-small-concerts', null],
            (bool) preg_match('/\bmusical\b/u', $text) => ['events-stage', 'events-stage-musicals'],
            (bool) preg_match('/stand.?up|mon[oó]logo|humor|c[oó]mic[oa]|comedia/u', $text) => ['events-stage', 'events-stage-comedy'],
            (bool) preg_match('/\bmag(ia|o)\b|ilusionis/u', $text) => ['events-stage', 'events-stage-magic'],
            (bool) preg_match('/\bdanza\b/u', $text) => ['events-stage', 'events-stage-dance'],
            default => ['events-stage', 'events-stage-theater'],
        };
    }
}
