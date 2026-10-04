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
 * Corral de Comedias de Alcalá de Henares (corraldealcala.com).
 *
 * Los espectáculos de la temporada salen de la API de WordPress
 * (`/wp/v2/espectaculos?temporada=…`, una petición), pero sin fechas: las pone
 * la plantilla de cada ficha, escritas a mano —«20 y 21 nov», «9 – 18 oct»,
 * «16 abr – 2 may»— con el año aparte y un horario por día de la semana
 * («Viernes y sábado: 19:30 h / Domingo: 18:00 h»). Con eso se sacan los pases:
 * los días sueltos tal cual, y en un rango, los días que tienen horario.
 *
 * Se abren todas las fichas de la temporada (~40, unos 20 s con la pausa de
 * `WebPage`): la API no dice cuándo es cada espectáculo.
 *
 * **Está en Alcalá de Henares**: el evento y la sala van con su municipio, pero
 * la fuente es de la agenda de Madrid (`city()`), como `FabrikSource`.
 */
final class CorralAlcalaSource implements EventSource
{
    private const SITE = 'https://www.corraldealcala.com';

    private const LAT          = 40.4822922;
    private const LNG          = -3.3645504;
    private const ADDRESS      = 'Pl. de Cervantes, 15, 28801 Alcalá de Henares';
    private const MUNICIPALITY = 'Alcalá de Henares';
    private const VENUE        = 'Corral de Comedias de Alcalá';

    /** Fichas como mucho por pasada (una temporada son ~40). */
    private const MAX_SHEETS = 45;

    private const WEEKDAYS = ['lunes' => 1, 'martes' => 2, 'miércoles' => 3, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sábado' => 6, 'sabado' => 6, 'domingo' => 7];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'corral-alcala';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $tz    = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $tz);
        // La temporada empieza en septiembre; en agosto ya está publicada la nueva.
        $year   = (int) $today->format('Y') - ((int) $today->format('n') < 8 ? 1 : 0);
        $season = $this->json(sprintf('%s/wp-json/wp/v2/temporada?slug=%d-%d&_fields=id', self::SITE, $year, $year + 1));
        $id     = $season[0]['id'] ?? null;
        if (!is_int($id)) {
            throw new \RuntimeException('No se encontró la temporada del Corral de Comedias de Alcalá');
        }
        $shows = $this->json(sprintf('%s/wp-json/wp/v2/espectaculos?temporada=%d&per_page=%d&_fields=link', self::SITE, $id, self::MAX_SHEETS));

        $passes = [];
        foreach ($shows as $show) {
            $url  = is_string($show['link'] ?? null) ? $show['link'] : null;
            $html = $url !== null ? $this->web->get($url) : null;
            if ($html === null) {
                continue;
            }
            array_push($passes, ...$this->performances($url, $html, $tz));
        }

        return Shows::group($passes);
    }

    /** La ficha ya se leyó en `fetch`: las fechas sólo están ahí. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: self::VENUE,
            city: self::MUNICIPALITY,
            categorySlug: 'culture-shows',
            latitude: self::LAT,
            longitude: self::LNG,
            address: self::ADDRESS,
            website: self::SITE . '/',
        );
    }

    /** @return list<ScrapedEvent> */
    private function performances(string $url, string $html, \DateTimeZone $tz): array
    {
        $xp    = Html::xpath($html);
        $title = Html::clean(Html::text($xp, '//h1[' . Html::hasClass('title-espectaculo') . ']'), 200);
        $year  = (int) Html::text($xp, '//div[' . Html::hasClass('caja-fechas') . ']/div[' . Html::hasClass('ano') . ']');
        $days  = (string) Html::text($xp, '//div[' . Html::hasClass('caja-fechas') . ']/div[' . Html::hasClass('dias') . ']');
        $image = Html::attr($xp, '//meta[@property="og:image"]', 'content');
        if ($title === null || $year < 2000 || $image === null) {
            return [];
        }

        $schedule = $this->schedule($this->field($xp, 'Horario'));
        $dates    = $this->dates($days, $year, $schedule, $tz);
        $tickets  = Html::attr($xp, '//a[' . Html::hasClass('btn-entradas') . ']', 'href');
        $age      = $this->field($xp, 'Edad recomendada') ?? $this->field($xp, 'Edada recomendada') ?? '';
        $info     = Html::clean(Html::attr($xp, '//meta[@property="og:description"]', 'content'), 400);
        [$subcategory, $subtype] = $this->classify($title . ' ' . $info, $age);

        $out = [];
        foreach ($dates as $start) {
            $out[] = new ScrapedEvent(
                source: $this->name(),
                externalId: trim((string) parse_url($url, \PHP_URL_PATH), '/'),
                title: $title,
                start: $start,
                end: $start->format('H:i') === '00:00' ? $start->setTime(23, 59) : null,
                city: self::MUNICIPALITY,
                venueName: self::VENUE,
                latitude: self::LAT,
                longitude: self::LNG,
                link: $tickets ?? $url,
                linkAction: $tickets !== null ? 'buy' : 'info',
                description: $info,
                imageUrl: $image,
                detailUrl: $url,
                venueAddress: self::ADDRESS,
                subcategory: $subcategory,
                subtype: $subtype,
            );
        }

        return $out;
    }

    /** El texto de un dato de la ficha («Horario», «Edad recomendada»). */
    private function field(\DOMXPath $xp, string $label): ?string
    {
        $dd = $xp->query(sprintf('//dt[starts-with(normalize-space(.), "%s")]/following-sibling::dd[1]', $label))->item(0);
        if ($dd === null) {
            return null;
        }
        // Los saltos de línea separan los días: se conservan.
        $text = preg_replace('/[ \t]+/u', ' ', str_replace("\xC2\xA0", ' ', $dd->textContent)) ?? '';

        return trim($text) === '' ? null : trim($text);
    }

    /**
     * «Viernes y sábado: 19:30 h / Domingo: 18:00 h» → día de la semana (ISO)
     * => primera hora. «Sábado: 18:00 h y 20:30 h»: la primera.
     *
     * @return array<int, string>
     */
    private function schedule(?string $text): array
    {
        $out = [];
        // A veces el día y la hora van en líneas distintas: se lee el texto entero.
        preg_match_all('/([\pL\s,]+?):\s*(\d{1,2}[:.]\d{2})/u', mb_strtolower((string) $text), $matches, \PREG_SET_ORDER);
        foreach ($matches as $m) {
            foreach (self::WEEKDAYS as $name => $iso) {
                if (preg_match('/\b' . $name . 's?\b/u', $m[1])) {
                    $out[$iso] ??= str_replace('.', ':', $m[2]);
                }
            }
        }

        return $out;
    }

    /**
     * Los días de función: «20 y 21 nov», «22 nov», «9 – 18 oct», «16 abr – 2 may».
     * En un rango, sólo los días con horario (si lo hay).
     *
     * @param array<int, string> $schedule
     *
     * @return list<\DateTimeImmutable>
     */
    private function dates(string $text, int $year, array $schedule, \DateTimeZone $tz): array
    {
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $at   = function (\DateTimeImmutable $day) use ($schedule): \DateTimeImmutable {
            $time = $schedule[(int) $day->format('N')] ?? (reset($schedule) ?: null);

            return $time !== null ? $day->setTime(...array_map('intval', explode(':', $time))) : $day;
        };

        // Rango: «9 – 18 oct» o «16 abr – 2 may».
        if (preg_match('/(\d{1,2})\s*(\pL+)?\s*[–—-]\s*(\d{1,2})\s+(\pL+)/u', $text, $m)) {
            $toMonth   = SpanishDate::month($m[4]);
            $fromMonth = $m[2] !== '' ? SpanishDate::month($m[2]) : $toMonth;
            if ($fromMonth === null || $toMonth === null) {
                return [];
            }
            $from = $this->day($year - ($fromMonth > $toMonth ? 1 : 0), $fromMonth, (int) $m[1], $tz);
            $to   = $this->day($year, $toMonth, (int) $m[3], $tz);
            if ($from === null || $to === null) {
                return [];
            }
            $out = [];
            for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
                if ($schedule === [] || isset($schedule[(int) $d->format('N')])) {
                    $out[] = $at($d);
                }
            }

            return $out;
        }

        // Días sueltos del mismo mes: «20 y 21 nov», «22 y 29 abr», «6 dic».
        if (!preg_match('/^([\d\s,y]+)\s+(\pL{3,})/u', trim($text), $m) || ($month = SpanishDate::month($m[2])) === null) {
            return [];
        }
        $out = [];
        foreach (preg_split('/\D+/', $m[1], -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $n) {
            if (($day = $this->day($year, $month, (int) $n, $tz)) !== null) {
                $out[] = $at($day);
            }
        }

        return $out;
    }

    private function day(int $year, int $month, int $day, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        return checkdate($month, $day, $year) ? (new \DateTimeImmutable('now', $tz))->setDate($year, $month, $day)->setTime(0, 0) : null;
    }

    /**
     * Teatro casi todo; lo de niños por la edad recomendada (hasta 8 años),
     * el flamenco y los conciertos por el título.
     *
     * @return array{0: string, 1: ?string}
     */
    private function classify(string $text, string $age): array
    {
        $text = mb_strtolower($text);

        if (preg_match('/a partir de ([1-8])\s*a[nñ]os|tarifa familiar/iu', $age)) {
            return ['events-kids', 'events-kids-theater'];
        }

        return match (true) {
            str_contains($text, 'flamenc')                                      => ['events-flamenco', 'events-flamenco-show'],
            (bool) preg_match('/\b(concierto|recital|canto y palabra)\b/u', $text) => ['events-small-concerts', null],
            (bool) preg_match('/\bmusical\b/u', $text)                          => ['events-stage', 'events-stage-musicals'],
            (bool) preg_match('/\b(comedia|humor)\b/u', $text)                  => ['events-stage', 'events-stage-comedy'],
            default                                                             => ['events-stage', 'events-stage-theater'],
        };
    }

    /** @return list<array<string, mixed>> */
    private function json(string $url): array
    {
        $raw  = $this->web->get($url);
        $data = $raw !== null ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('No se pudo leer %s', $url));
        }

        return $data;
    }
}
