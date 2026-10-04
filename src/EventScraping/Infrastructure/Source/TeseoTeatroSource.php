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
 * Teseo Teatro (teseoteatro.es), sala pequeña en la Ronda de Segovia: teatro
 * infantil los fines de semana y teatro e improvisación para adultos.
 *
 * Su cartelera (web de Duda) es el programa del mes escrito a mano: cada obra
 * con su enlace a la ficha y los pases («Sábado 17/10 12:30h», «Todos los
 * jueves a las 20:30h»). Las fechas y el título se leen de ahí; el cartel y la
 * sinopsis, de la ficha (`og:image`, `og:description`).
 *
 * - ⚠️ Alguna ficha tiene el cartel cambiado con otra (Jamming Kombat y
 *   Matching, al escribir esto): es cosa de su web.
 * - **Infantil o adultos** no lo dice ningún dato: es infantil la obra con
 *   algún pase de fin de semana antes de las 18 h, o que habla de bebés,
 *   niños o campaña escolar.
 * - «Todos los jueves» vale hasta final del mes del programa
 *   («Programación Octubre 2026»).
 * - Las entradas se venden en Atrápalo: el botón es esa compra.
 */
final class TeseoTeatroSource implements EventSource
{
    private const SITE    = 'https://www.teseoteatro.es';
    private const LISTING = self::SITE . '/cartelera';
    private const NAME    = 'Teseo Teatro';
    private const LAT     = 40.4084953;
    private const LNG     = -3.7158866;
    private const ADDRESS = 'Ronda de Segovia, 61, 28005 Madrid';

    private const WEEKDAYS = ['lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'teseo-teatro';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::LISTING);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la cartelera de Teseo Teatro');
        }

        // Texto plano con una marca donde va cada enlace a una ficha: el título
        // queda delante de la primera marca de la obra y los pases detrás.
        $html  = (string) preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);
        $html  = (string) preg_replace('#<a\b[^>]*href="(/producto/[^"]+)"[^>]*>#i', ' [[$1]] ', $html);
        $text  = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text  = trim((string) preg_replace('/[\s\x{200B}\x{FEFF}]+/u', ' ', $text));
        $parts = preg_split('/\[\[(\/producto\/[^\]]+)\]\]/', $text, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [];

        $monthEnd = null;
        if (preg_match('/Programaci[oó]n (\p{L}+) (\d{4})/u', $text, $m) && ($month = SpanishDate::month($m[1])) !== null) {
            $monthEnd = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->setDate((int) $m[2], $month, 1)->modify('last day of this month')->setTime(23, 59);
        }

        $seen   = [];
        $passes = [];
        for ($i = 1; $i < count($parts); $i += 2) {
            $path = $parts[$i];
            if (isset($seen[$path])) {
                continue;
            }
            $after = (string) preg_replace('/RESERVA.*$/su', '', $parts[$i + 1] ?? '');
            $slots = $this->slots($after, $monthEnd);
            if ($slots === []) {
                continue;
            }
            $seen[$path] = true;

            $before = $parts[$i - 1];
            $title  = Html::clean((string) preg_replace('/^.*(?:Más información|Programación \p{L}+ \d{4})/su', '', $before), 200);
            $play   = $this->play(self::SITE . $path);
            if ($title === null || $play === null) {
                continue;
            }

            $kids = (bool) preg_match('/beb[eé]s|niñ[oa]s|campaña escolar|infantil/iu', $play['text']);
            foreach ($slots as [$start, $end, $weekdays]) {
                if ($weekdays === null && (int) $start->format('N') >= 6 && (int) $start->format('G') < 18) {
                    $kids = true;
                }
            }
            [$subcategory, $subtype] = $kids ? ['events-kids', 'events-kids-theater'] : $this->classify(mb_strtolower($title . ' ' . $play['text']));

            foreach ($slots as [$start, $end, $weekdays]) {
                $passes[] = new ScrapedEvent(
                    source: $this->name(),
                    externalId: trim(basename($path), '_-'),
                    title: $title,
                    start: $start,
                    end: $end,
                    city: $this->city(),
                    venueName: self::NAME,
                    latitude: self::LAT,
                    longitude: self::LNG,
                    link: $play['tickets'] ?? self::SITE . $path,
                    linkAction: $play['tickets'] !== null ? 'buy' : 'info',
                    description: $play['description'],
                    imageUrl: $play['image'],
                    detailUrl: self::SITE . $path,
                    weekdays: $weekdays,
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
            website: self::SITE . '/',
        );
    }

    /**
     * Los pases de una obra: «Sábado 17/10 12:30h» uno a uno, o «Todos los
     * jueves a las 20:30h» como un tramo hasta fin de mes con ese día.
     *
     * @return list<array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable, 2: ?list<int>}>
     */
    private function slots(string $text, ?\DateTimeImmutable $monthEnd): array
    {
        $out = [];
        preg_match_all('#(\d{1,2})/(\d{1,2})\s*(?:a las\s*)?(\d{1,2}[:.]\d{2})?#u', $text, $all, \PREG_SET_ORDER);
        foreach ($all as $m) {
            $start = SpanishDate::build((int) $m[1], (int) $m[2], $m[3] ?? null);
            if ($start !== null) {
                $out[] = [$start, ($m[3] ?? '') === '' ? $start->setTime(23, 59) : null, null];
            }
        }

        $plain = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e']);
        if ($out === [] && $monthEnd !== null && preg_match('/todos los (\p{L}+) (?:a las )?(\d{1,2})[:.](\d{2})/u', $plain, $w)
            // «jueves», pero también «sabados» y «domingos».
            && ($day = self::WEEKDAYS[$w[1]] ?? self::WEEKDAYS[rtrim($w[1], 's')] ?? null) !== null
        ) {
            // Desde el próximo día de esos.
            $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));
            $first = $today->modify('+' . ((7 + $day - (int) $today->format('N')) % 7) . ' days');
            if ($monthEnd > $first) {
                $out[] = [$first->setTime((int) $w[2], (int) $w[3]), $monthEnd, [$day]];
            }
        }

        return $out;
    }

    /** @return array{image: ?string, description: ?string, tickets: ?string, text: string}|null */
    private function play(string $url): ?array
    {
        $html = $this->web->get($url);
        if ($html === null) {
            return null;
        }
        $xp    = Html::xpath($html);
        $image = Html::attr($xp, '//meta[@property="og:image"]', 'content');
        if ($image === null) {
            return null;
        }
        $description = Html::clean((string) preg_replace('/^SINOPSIS:?\s*/u', '', (string) Html::attr($xp, '//meta[@property="og:description"]', 'content')));

        return [
            'image'       => $image,
            'description' => $description,
            'tickets'     => Html::attr($xp, '//a[contains(@href, "atrapalo.com/entradas")]', 'href'),
            // Sólo la sinopsis: el pie de todas las fichas habla de «teatro infantil».
            'text'        => (string) $description,
        ];
    }

    /** @return array{0: string, 1: string} */
    private function classify(string $text): array
    {
        return match (true) {
            (bool) preg_match('/improvisaci|\bimpro\b|c[oó]mic|humor|mon[oó]logo/u', $text) => ['events-stage', 'events-stage-comedy'],
            default => ['events-stage', 'events-stage-theater'],
        };
    }
}
