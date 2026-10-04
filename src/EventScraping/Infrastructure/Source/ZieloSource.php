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
 * Zielo Shopping Pozuelo (zielo.es/eventos), el centro comercial de Pozuelo de
 * Alarcón: teatro infantil cada fin de semana y talleres para niños.
 *
 * Sus eventos son entradas de WordPress (`/wp-json/wp/v2/eventos`), pero las
 * fechas no están en ningún campo: van escritas en el texto, y de dos formas.
 *
 * - **Una programación** («Viernes. 2 de octubre: LA PRINCESA ENFADADA (Cuenta
 *   cuento)») con las horas aparte, por día de la semana («Sábado: Dos pases,
 *   … a las 12:00 y … a las 18:15h.»). Cada obra es un evento con sus pases
 *   (`Shows`), y su sinopsis es el párrafo que lleva su nombre más abajo.
 * - **Unas fechas sueltas** («Jueves 24 de septiembre» y debajo «Turnos a las
 *   17:30 h y 19:15 h»): un evento, el de la entrada, con todos sus turnos.
 *
 * Se leen sólo las entradas recientes (`RECENT_DAYS`): el listado guarda las
 * de años anteriores, y abrirlas todas para descartarlas por la fecha es
 * trabajo tirado. Sin año en el texto: lo deduce `SpanishDate`.
 *
 * Está en Pozuelo de Alarcón, no en Madrid: el evento y el negocio van con su
 * municipio, pero la fuente es de la agenda de Madrid (ver `FabrikSource`).
 */
final class ZieloSource implements EventSource
{
    private const SITE = 'https://zielo.es';
    private const API  = self::SITE . '/wp-json/wp/v2/eventos?per_page=10&_fields=id,slug,link,title,modified,featured_media';

    private const NAME         = 'Zielo Shopping Pozuelo';
    private const LAT          = 40.4414057;
    private const LNG          = -3.7841339;
    private const ADDRESS      = 'Av. de Europa, 26B, 28224 Pozuelo de Alarcón, Madrid';
    private const MUNICIPALITY = 'Pozuelo de Alarcón';

    private const RECENT_DAYS = 120;

    private const WEEKDAYS = '(lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bado|domingo)';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'zielo';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $raw   = $this->web->get(self::API);
        $posts = $raw !== null ? json_decode($raw, true) : null;
        if (!is_array($posts)) {
            throw new \RuntimeException('No se pudo leer la agenda de Zielo');
        }

        $since  = new \DateTimeImmutable(sprintf('-%d days', self::RECENT_DAYS));
        $passes = [];

        foreach ($posts as $post) {
            $url  = is_string($post['link'] ?? null) ? $post['link'] : null;
            $slug = is_string($post['slug'] ?? null) ? $post['slug'] : null;
            if ($url === null || $slug === null || new \DateTimeImmutable((string) ($post['modified'] ?? '2000-01-01')) < $since) {
                continue;
            }

            $html  = $this->web->get($url);
            $image = $this->image((int) ($post['featured_media'] ?? 0));
            if ($html === null || $image === null) {
                continue;
            }

            $title = Html::clean($post['title']['rendered'] ?? null, 200) ?? $slug;
            array_push($passes, ...$this->passes($slug, $title, $url, $image, $this->lines($html)));
        }

        return Shows::group($passes);
    }

    /** Todo sale de la entrada, que ya se leyó en `fetch`. */
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
            city: self::MUNICIPALITY,
            categorySlug: 'experiences',
            latitude: self::LAT,
            longitude: self::LNG,
            address: self::ADDRESS,
            website: self::SITE . '/',
        );
    }

    /**
     * Los pases que cuenta el texto de una entrada.
     *
     * @param list<string> $lines
     *
     * @return list<ScrapedEvent>
     */
    private function passes(string $slug, string $postTitle, string $url, string $image, array $lines): array
    {
        $byWeekday = $this->timesByWeekday($lines);
        $intro     = $this->intro($lines);
        $out       = [];

        foreach ($lines as $i => $line) {
            if (!preg_match('/^' . self::WEEKDAYS . '\.?,?\s+(\d{1,2})\s+de\s+([a-záéíóú]+)\s*(?::\s*(.+))?$/iu', $line, $m)) {
                continue;
            }
            $month = SpanishDate::month($m[3]);
            $day   = $month !== null ? SpanishDate::build((int) $m[2], $month, null) : null;
            if ($day === null) {
                continue;
            }

            // La hora, en la línea siguiente («Turnos a las 17:30 h y 19:15 h»)
            // o en el horario por día de la semana.
            $next  = $lines[$i + 1] ?? '';
            $times = preg_match('/\ba las\b/iu', $next) && !preg_match('/^' . self::WEEKDAYS . '/iu', $next)
                ? $this->times($next)
                : ($byWeekday[$this->weekday($m[1])] ?? []);

            $show = trim($m[4] ?? '');
            if ($show !== '') {
                [$title, $kind] = $this->show($show);
                $externalId  = $slug . '/' . $this->slug($title);
                $description = $this->synopsis($lines, $title) ?? $intro;
                [$subcategory, $subtype] = $this->classify($kind . ' ' . $title, true);
            } else {
                $title       = $postTitle;
                $externalId  = $slug;
                $description = $intro;
                [$subcategory, $subtype] = $this->classify($postTitle . ' ' . implode(' ', $lines), false);
            }

            foreach ($times === [] ? [null] : $times as $time) {
                $start = $time === null ? $day : $day->setTime($time[0], $time[1]);
                $out[] = new ScrapedEvent(
                    source: $this->name(),
                    externalId: $externalId,
                    title: $title,
                    start: $start,
                    end: $time === null ? $day->setTime(23, 59) : null,
                    city: self::MUNICIPALITY,
                    venueName: self::NAME,
                    latitude: self::LAT,
                    longitude: self::LNG,
                    link: $url,
                    description: $description,
                    imageUrl: $image,
                    detailUrl: $url,
                    venueAddress: self::ADDRESS,
                    subcategory: $subcategory,
                    subtype: $subtype,
                );
            }
        }

        return $out;
    }

    /**
     * Las líneas del texto de la entrada (los bloques de texto de Elementor),
     * con los saltos de línea que el texto tiene a la vista.
     *
     * @return list<string>
     */
    private function lines(string $html): array
    {
        $xp    = Html::xpath($html);
        $lines = [];
        foreach ($xp->query('//div[' . Html::hasClass('elementor-widget-text-editor') . ']') as $node) {
            $inner = (string) $node->ownerDocument?->saveHTML($node);
            $inner = (string) preg_replace('#<br\s*/?>|</(p|h\d|li|div)>#i', "\n", $inner);
            foreach (explode("\n", html_entity_decode(strip_tags($inner), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')) as $line) {
                $line = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $line));
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    /**
     * «Viernes: Un pase a las 18:15h.» → [5 => [[18, 15]]].
     *
     * @param list<string> $lines
     *
     * @return array<int, list<array{0: int, 1: int}>>
     */
    private function timesByWeekday(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^' . self::WEEKDAYS . '\s*:\s*(.+)$/iu', $line, $m)) {
                $out[$this->weekday($m[1])] = $this->times($m[2]);
            }
        }

        return $out;
    }

    /** @return list<array{0: int, 1: int}> */
    private function times(string $text): array
    {
        preg_match_all('/\b(\d{1,2})[:.](\d{2})\s*h?/u', $text, $m, \PREG_SET_ORDER);

        return array_values(array_filter(
            array_map(fn ($t) => [(int) $t[1], (int) $t[2]], $m),
            fn ($t) => $t[0] < 24 && $t[1] < 60,
        ));
    }

    private function weekday(string $name): int
    {
        $key = mb_substr(strtr(mb_strtolower($name), ['á' => 'a', 'é' => 'e']), 0, 2);

        return ['lu' => 1, 'ma' => 2, 'mi' => 3, 'ju' => 4, 'vi' => 5, 'sa' => 6, 'do' => 7][$key] ?? 0;
    }

    /**
     * «LA PRINCESA ENFADADA (Cuenta cuento)» → ['La princesa enfadada', 'cuenta cuento'].
     *
     * @return array{0: string, 1: string}
     */
    private function show(string $text): array
    {
        $kind = preg_match('/\(([^)]+)\)\s*$/u', $text, $m) ? mb_strtolower($m[1]) : '';
        $name = trim((string) preg_replace('/\([^)]*\)\s*$/u', '', $text));
        // En mayúsculas en la web: en la tarjeta se lee mejor como frase.
        if ($name === mb_strtoupper($name)) {
            $name = mb_strtoupper(mb_substr($name, 0, 1)) . mb_strtolower(mb_substr($name, 1));
        }

        return [$name, $kind];
    }

    /**
     * La sinopsis de una obra: las líneas que siguen a la que lleva su nombre
     * (en mayúsculas) fuera de la programación, hasta la obra siguiente.
     *
     * @param list<string> $lines
     */
    private function synopsis(array $lines, string $title): ?string
    {
        $needle = mb_strtoupper($title);
        $text   = [];
        $inside = false;
        foreach ($lines as $line) {
            // «LA PRINCESA ENFADADA (Cuenta cuento)»: el tipo, entre
            // paréntesis, va en minúsculas.
            $name      = trim((string) preg_replace('/\([^)]*\)\s*$/u', '', $line));
            $isHeading = $name === mb_strtoupper($name) && preg_match('/\p{Lu}{3}/u', $name);
            if ($inside) {
                if ($isHeading) {
                    break;
                }
                $text[] = $line;
            } elseif ($isHeading && str_starts_with($line, $needle) && !preg_match('/^' . self::WEEKDAYS . '/iu', $line)) {
                $inside = true;
            }
        }

        return Html::clean(implode(' ', $text), 400);
    }

    /**
     * El primer párrafo con algo de texto, para lo que no tiene sinopsis.
     *
     * @param list<string> $lines
     */
    private function intro(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (mb_strlen($line) > 80) {
                return Html::clean($line, 400);
            }
        }

        return null;
    }

    /**
     * Niños, salvo lo que no lo dice por ningún lado (un concierto, un taller
     * de perfumería por San Valentín). Una obra de la programación infantil
     * siempre es de niños.
     *
     * @return array{0: string, 1: ?string}
     */
    private function classify(string $text, bool $kids): array
    {
        $text = mb_strtolower($text);
        $kids = $kids || preg_match('/\b(niñ[oa]s?|infantil|peques|familia|kids|little)\b/u', $text);

        return match (true) {
            $kids && str_contains($text, 'cuento')                    => ['events-kids', 'events-kids-storytelling'],
            $kids && (bool) preg_match('/teatro|t[ií]teres|danza|magia/u', $text) => ['events-kids', 'events-kids-theater'],
            $kids && (bool) preg_match('/taller|cocina|chef|atelier/u', $text)    => ['events-kids', 'events-kids-workshops'],
            $kids                                                     => ['events-kids', 'events-kids-family-plans'],
            str_contains($text, 'concierto') || str_contains($text, 'coro') => ['events-small-concerts', null],
            (bool) preg_match('/taller|atelier|por un d[ií]a/u', $text) => ['events-experiences', 'events-experiences-workshops'],
            default                                                   => ['events-other', null],
        };
    }

    /** La imagen destacada de la entrada, del tamaño original. */
    private function image(int $mediaId): ?string
    {
        if ($mediaId <= 0) {
            return null;
        }
        $raw   = $this->web->get(self::SITE . '/wp-json/wp/v2/media/' . $mediaId . '?_fields=source_url');
        $media = $raw !== null ? json_decode($raw, true) : null;

        return is_string($media['source_url'] ?? null) ? $media['source_url'] : null;
    }

    private function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return mb_substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-'), 0, 120);
    }
}
