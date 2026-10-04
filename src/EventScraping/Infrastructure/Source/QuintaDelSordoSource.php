<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Quinta del Sordo (quintadelsordo.com), centro cultural de La Latina:
 * exposiciones, talleres, clubes de lectura, cineclub y ferias de fanzines.
 *
 * Su página «Eventos» es una lista de fichas sin fecha, mezclando lo que viene
 * con lo de hace meses, y **la fecha va escrita a mano en cada ficha**, cada
 * una a su manera: «FECHAS: Del 3 al 10 de octubre de 2026», «Fecha: Viernes 23
 * de octubre | Hora: de 16:00 a 19:00 h», «CUÁNDO: 18 de septiembre 2026 | HORA:
 * A partir de las 19h». Se abren todas (~30) y se lee la primera de esas
 * etiquetas.
 *
 * ⚠️ La más frágil del bloque: lo que no se entiende se pierde sin avisar.
 * - Una ficha sin etiqueta de fecha (convocatorias, grupos de investigación,
 *   programas de curso entero) no es un plan con día y se salta.
 * - Un club de lectura dice la temporada («de octubre 2026 a junio 2027») y
 *   luego cada sesión («fechas | 22 de octubre a las 19h | libro…»): sale la
 *   primera sesión que aparece, no todas.
 * - Sin año escrito, el de `SpanishDate` (el mes que ya pasó es del año que
 *   viene), salvo que caiga a más de tres meses: es una ficha vieja que sigue
 *   en la lista. Lo que queda atrás lo quita el filtro de fechas.
 * - «Convocatoria…» es la llamada a expositores, no la feria: fuera.
 * - Todo va a la Quinta, aunque alguna actividad sea en su librería (Elástica,
 *   a dos calles).
 */
final class QuintaDelSordoSource implements EventSource
{
    private const SITE    = 'https://quintadelsordo.com';
    private const LISTING = self::SITE . '/eventos-2/';
    private const NAME    = 'Quinta del Sordo';
    private const LAT     = 40.4093008;
    private const LNG     = -3.7143299;
    private const ADDRESS = 'Calle del Rosario, 15, 28005 Madrid';

    private const MONTHS = 'enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'quinta-del-sordo';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::LISTING);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la agenda de la Quinta del Sordo');
        }

        preg_match_all('#href="(https://quintadelsordo\.com/portfolio/[^"/]+/)"#', $html, $m);
        foreach (array_unique($m[1]) as $url) {
            $event = $this->event($url);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    /** La ficha ya se ha abierto en `fetch` para la fecha: trae de todo. */
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
            categorySlug: 'culture-shows',
            latitude: self::LAT,
            longitude: self::LNG,
            address: self::ADDRESS,
            website: self::SITE . '/',
        );
    }

    private function event(string $url): ?ScrapedEvent
    {
        $html = $this->web->get($url);
        if ($html === null) {
            return null;
        }

        $xp    = Html::xpath($html);
        $title = Html::clean(Html::attr($xp, '//meta[@property="og:title"]', 'content'), 200);
        $image = Html::attr($xp, '//meta[@property="og:image"]', 'content');
        // El contenido empieza tras el menú, que acaba en «CAFÉ».
        $text  = Html::clean(Html::text($xp, '//body'), 100_000) ?? '';
        $text  = mb_substr($text, (int) mb_strpos($text, 'CAFÉ'), 8000);
        [$start, $end] = $this->dates($text);
        // La convocatoria para expositores repite la fecha de la feria.
        if ($title === null || $image === null || $start === null || str_starts_with(mb_strtolower($title), 'convocatoria')) {
            return null;
        }

        [$subcategory, $subtype] = $this->classify(mb_strtolower($title . ' ' . $url));

        return new ScrapedEvent(
            source: $this->name(),
            externalId: basename(rtrim($url, '/')),
            title: $title,
            start: $start,
            end: $end,
            city: $this->city(),
            venueName: self::NAME,
            latitude: self::LAT,
            longitude: self::LNG,
            link: $url,
            linkAction: 'info',
            description: Html::clean(Html::attr($xp, '//meta[@property="og:description"]', 'content')),
            imageUrl: $image,
            detailUrl: $url,
            venueAddress: self::ADDRESS,
            subcategory: $subcategory,
            subtype: $subtype,
        );
    }

    /**
     * Lo que sigue a la primera etiqueta de fecha: un rango («Del 16 al 22 de
     * octubre», «Del 19 de marzo al 19 de junio de 2026») o un día con su hora
     * («Sábado 10 de octubre de 2026, 11:00 – 15:00», «30 de mayo, 12:00h»).
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function dates(string $text): array
    {
        if (!preg_match('/\b(?:fechas?|cu[áa]ndo|d[íi]a)\b\s*:?\s*(.{0,160})/iu', $text, $m)) {
            return [null, null];
        }
        $after = $m[1];

        if (preg_match('/del\s+(\d{1,2})(?:\s+de\s+(' . self::MONTHS . '))?\s+al\s+(\d{1,2})\s+de\s+(' . self::MONTHS . ')(?:\s+(?:de\s+)?(\d{4}))?/iu', $after, $r)) {
            $to   = $this->day((int) $r[3], $r[4], $r[5] ?? null);
            $from = $to !== null ? $this->day((int) $r[1], $r[2] !== '' ? $r[2] : $r[4], $r[5] ?? null) : null;
            if ($from !== null && $from > $to) {
                $from = $from->modify('-1 year');
            }

            return $from !== null ? [$from, $to->setTime(23, 59)] : [null, null];
        }

        if (!preg_match('/(\d{1,2})\s+de\s+(' . self::MONTHS . ')(?:,?\s+(?:de\s+)?(\d{4}))?(.{0,60})/iu', $after, $d)) {
            return [null, null];
        }
        $day = $this->day((int) $d[1], $d[2], $d[3] !== '' ? $d[3] : null);
        if ($day === null) {
            return [null, null];
        }

        // «11:00 – 15:00», «de 16:00 a 19:00 h», «12:00h», «a partir de las 19h».
        if (preg_match('/(\d{1,2})(?::(\d{2}))?\s*h?\s*(?:–|-|a)\s*(\d{1,2})(?::(\d{2}))\s*h?/u', $d[4], $t)) {
            return [$day->setTime((int) $t[1], (int) ($t[2] ?: 0)), $day->setTime((int) $t[3], (int) $t[4])];
        }
        if (preg_match('/(\d{1,2})(?::(\d{2}))?\s*h\b|(\d{1,2}):(\d{2})/u', $d[4], $t)) {
            return [$day->setTime((int) ($t[1] ?: $t[3]), (int) (($t[2] ?? '') ?: ($t[4] ?? 0))), null];
        }

        return [$day, $day->setTime(23, 59)];
    }

    private function day(int $day, string $monthName, ?string $year): ?\DateTimeImmutable
    {
        $month = SpanishDate::month($monthName);
        if ($month === null) {
            return null;
        }
        if ($year === null) {
            // Sin año, una ficha vieja («29 de enero», de este enero) saldría en
            // el del año que viene: nadie anuncia nada a más de tres meses.
            $date = SpanishDate::build($day, $month, null);

            return $date !== null && $date <= new \DateTimeImmutable('+3 months') ? $date : null;
        }

        return checkdate($month, $day, (int) $year)
            ? (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->setDate((int) $year, $month, $day)->setTime(0, 0)
            : null;
    }

    /** @return array{0: string, 1: ?string} */
    private function classify(string $text): array
    {
        $kids = str_contains($text, 'familia') || str_contains($text, 'infantil') || str_contains($text, 'niñ');

        return match (true) {
            str_contains($text, 'exposici')                                                     => ['events-art', 'events-art-temporary'],
            $kids                                                                               => ['events-kids', 'events-kids-workshops'],
            (bool) preg_match('/taller|workshop|laboratorio|p[íi]ldora|curso/u', $text)         => ['events-experiences', 'events-experiences-workshops'],
            str_contains($text, 'cine')                                                         => ['events-experiences', 'events-experiences-cinema'],
            (bool) preg_match('/feria|fanzine|mercad/u', $text)                                 => ['events-markets', 'events-markets-vintage-crafts'],
            (bool) preg_match('/fest\b|concierto/u', $text)                                     => ['events-small-concerts', null],
            default                                                                             => ['events-experiences', null],
        };
    }
}
