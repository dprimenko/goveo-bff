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
 * Espacio Abierto Quinta de los Molinos (espacioabiertoqm.es), el centro
 * municipal de creación para la infancia y la adolescencia, en San Blas.
 *
 * Talleres, proyecciones y espectáculos para niños y familias. Las actividades
 * son un tipo propio de WordPress (`/wp-json/wp/v2/programacion`, las más
 * recientes primero), pero la API no trae las fechas: están escritas a mano en
 * la ficha («3, 4, 10 y 11 de octubre», «Del 11 de octubre al 29 de
 * noviembre»), en el primer párrafo de `.datos1`, y la hora en el segundo.
 *
 * - Las sesiones **escolares y para docentes** se quitan: no son un plan para
 *   el público.
 * - «Histórico: …» son las fechas pasadas de la misma actividad y se ignoran.
 * - Lo que no se entiende («De octubre a diciembre») se descarta antes que
 *   inventar un día.
 * - Gratis o con entrada en taquilla: el enlace es la ficha.
 * - Del Ayuntamiento, pero no está en `madrid-datos` (sólo el parque, con
 *   otras cosas); esMadrid trae alguna actividad suelta.
 */
final class EspacioAbiertoSource implements EventSource
{
    private const SITE    = 'https://www.espacioabiertoqm.es/';
    private const API     = self::SITE . 'wp-json/wp/v2/programacion?per_page=50&_fields=link,title,class_list';
    private const NAME    = 'Espacio Abierto Quinta de los Molinos';
    private const LAT     = 40.4475578;
    private const LNG     = -3.629127;
    private const ADDRESS = 'Calle de Juan Ignacio Luca de Tena, 20, 28027 Madrid';

    /** Públicos que no son de la agenda (`programacion_edades-*`). */
    private const NOT_PUBLIC = ['escolar', 'docente'];

    private const MONTH = '(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'espacio-abierto-qm';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $json = $this->web->get(self::API);
        $list = $json === null ? null : json_decode($json, true);
        if (!is_array($list)) {
            throw new \RuntimeException('No se pudo descargar la programación de Espacio Abierto');
        }

        $passes = [];
        foreach ($list as $item) {
            $link = $item['link'] ?? null;
            if (!is_string($link) || !$this->forPublic((array) ($item['class_list'] ?? []))) {
                continue;
            }
            $html = $this->web->get($link);
            if ($html === null) {
                continue;
            }

            $xp    = Html::xpath($html);
            $title = Html::clean(html_entity_decode((string) ($item['title']['rendered'] ?? ''), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), 200);
            $image = Html::attr($xp, '//meta[@property="og:image"]', 'content');
            $when  = Html::text($xp, '//div[' . Html::hasClass('datos1') . ']/p[1]');
            $hours = Html::text($xp, '//div[' . Html::hasClass('datos1') . ']/p[2]');
            if ($title === null || $image === null || $when === null || preg_match('/sesi[oó]n escolar/iu', $title)) {
                continue;
            }

            $description = Html::clean(Html::attr($xp, '//meta[@property="og:description"]', 'content'));
            $description = $description === null ? null : (string) preg_replace('/\s*\[…\]$/u', '…', $description);
            [$subcategory, $subtype] = $this->classify(mb_strtolower($title . ' ' . $description));
            $id = trim((string) parse_url($link, \PHP_URL_PATH), '/');

            foreach ($this->dates($when, $hours) as [$start, $end]) {
                $passes[] = new ScrapedEvent(
                    source: $this->name(),
                    externalId: mb_substr(str_replace('actividades/', '', $id), 0, 200),
                    title: $title,
                    start: $start,
                    end: $end,
                    city: $this->city(),
                    venueName: self::NAME,
                    latitude: self::LAT,
                    longitude: self::LNG,
                    link: $link,
                    description: $description,
                    imageUrl: $image,
                    detailUrl: $link,
                    venueAddress: self::ADDRESS,
                    subcategory: $subcategory,
                    subtype: $subtype,
                );
            }
        }

        return Shows::group($passes);
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
            categorySlug: 'culture-shows',
            latitude: self::LAT,
            longitude: self::LNG,
            address: self::ADDRESS,
            website: self::SITE,
        );
    }

    /** @param list<string> $classes */
    private function forPublic(array $classes): bool
    {
        $audiences = [];
        foreach ($classes as $class) {
            if (preg_match('/^programacion_edades-(.+)$/', (string) $class, $m)) {
                $audiences[] = $m[1];
            }
        }

        return $audiences === [] || array_diff($audiences, self::NOT_PUBLIC) !== [];
    }

    /**
     * Los días de la actividad, cada uno con su hora (la primera del horario)
     * o el día entero. Un rango «Del 11 de octubre al 29 de noviembre» es un
     * solo tramo.
     *
     * @return list<array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable}>
     */
    private function dates(string $when, ?string $hours): array
    {
        $text = mb_strtolower((string) preg_replace('/hist[oó]rico.*$/iu', '', $when));
        $time = $this->time($hours ?? '') ?? $this->time($text);

        if (preg_match('/del (\d{1,2}) de ' . self::MONTH . ' al (\d{1,2}) de ' . self::MONTH . '/u', $text, $r)) {
            $from = SpanishDate::build((int) $r[1], (int) SpanishDate::month($r[2]), $time);
            $to   = SpanishDate::build((int) $r[3], (int) SpanishDate::month($r[4]), null);

            return $from !== null && $to !== null && $to >= $from->setTime(0, 0) ? [[$from, $to->setTime(23, 59)]] : [];
        }

        $out = [];
        // «1, 2, 7 y 8 de octubre», «12, 19 y 20 de diciembre de 2026», «20 noviembre».
        preg_match_all('/((?:\d{1,2}\s*(?:,|y)\s*)*\d{1,2})\s+(?:de\s+)?' . self::MONTH . '/u', $text, $groups, \PREG_SET_ORDER);
        foreach ($groups as $g) {
            $month = (int) SpanishDate::month($g[2]);
            preg_match_all('/\d{1,2}/', $g[1], $days);
            foreach ($days[0] as $day) {
                $date = SpanishDate::build((int) $day, $month, $time);
                if ($date !== null) {
                    $out[] = [$date, $time === null ? $date->setTime(23, 59) : null];
                }
            }
        }

        return $out;
    }

    /**
     * «10.45 h», «16:00h», «17 h», «de 11 a 14 h»: la primera hora del texto
     * (la alternativa que empieza antes es la que casa).
     */
    private function time(string $text): ?string
    {
        if (!preg_match('/\b(\d{1,2})[.:](\d{2})|\bde (\d{1,2}) a \d|\b(\d{1,2})\s*h\b/u', $text, $m)) {
            return null;
        }
        $hour   = (int) ($m[1] !== '' ? $m[1] : ($m[3] ?? '') . ($m[4] ?? ''));
        $minute = $m[1] !== '' ? (int) $m[2] : 0;

        return $hour < 24 ? sprintf('%02d:%02d', $hour, $minute) : null;
    }

    /** @return array{0: string, 1: string} */
    private function classify(string $text): array
    {
        return match (true) {
            (bool) preg_match('/taller|experimentaci[oó]n|construir|cuaderno|creaci[oó]n/u', $text) => ['events-kids', 'events-kids-workshops'],
            (bool) preg_match('/teatro|t[ií]teres|concierto|espect[aá]culo|danza|m[uú]sica/u', $text)  => ['events-kids', 'events-kids-theater'],
            (bool) preg_match('/cuento|narraci[oó]n/u', $text)                                         => ['events-kids', 'events-kids-storytelling'],
            default                                                                                   => ['events-kids', 'events-kids-family-plans'],
        };
    }
}
