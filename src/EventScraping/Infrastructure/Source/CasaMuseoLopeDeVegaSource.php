<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Casa Museo Lope de Vega (de la Comunidad de Madrid, con web propia).
 *
 * Su calendario es un artículo de Joomla **por cuatrimestre** («Sep/Dic 2026»),
 * una tabla escrita a mano: foto con enlace a la ficha, título y la fecha en
 * texto («6 de octubre 2026», «Del 8 de octubre al 8 de diciembre 2026»). La
 * dirección de cada cuatrimestre no sigue ningún patrón
 * (`calendario-segundo-cuatrimestre-2` es el tercero): se busca en el menú por
 * su rótulo.
 *
 * Sin horas: cada actividad es su día o su rango entero. La ficha da el cartel
 * grande y el texto. ⚠️ Es de las más frágiles: depende de cómo escriban la
 * fecha.
 *
 * `comunidad-madrid` no trae sus actos aunque estén en su agenda: su lista de
 * salas ya leídas (`COVERED`) lleva «lope de vega» por el teatro de Gran Vía y
 * casa también con la casa museo.
 */
final class CasaMuseoLopeDeVegaSource implements EventSource
{
    private const SITE = 'https://www.casamuseolopedevega.org';

    private const VENUE = [
        'name'    => 'Casa Museo Lope de Vega',
        'lat'     => 40.4143821,
        'lng'     => -3.6974563,
        'address' => 'Calle de Cervantes, 11, 28014 Madrid',
        'website' => 'https://www.casamuseolopedevega.org/es/',
    ];

    /** Lo que no es para el público del museo. */
    private const EXCLUDED = '/fuera del museo|para mayores|colegios|escolar|conferencia|curso/iu';

    private const DATE = '(\d{1,2})\s+de\s+([a-záéíóú]+)';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'casa-museo-lope-de-vega';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $tz    = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $tz);
        $pages = $this->calendars($today);
        if ($pages === []) {
            throw new \RuntimeException('No se encontró el calendario de la Casa Museo Lope de Vega');
        }

        $seen = [];
        foreach ($pages as $page) {
            $html = $this->web->get($page);
            if ($html === null) {
                continue;
            }
            $xp = Html::xpath($html);

            foreach ($xp->query('//tr[td/a[starts-with(@href, "/es/") or starts-with(@href, "https://www.casamuseolopedevega.org/")]/img]') as $row) {
                $url   = Html::absolute(Html::attr($xp, './td/a[img and (starts-with(@href, "/es/") or starts-with(@href, "https://www.casamuseolopedevega.org/"))]', 'href', $row), self::SITE);
                $title = Html::clean(Html::text($xp, './/h1', $row), 200);
                // La fecha es lo que queda de la celda al quitar el título.
                $cell  = $xp->query('./td[.//h1]', $row)?->item(0);
                $when  = $cell !== null ? trim(str_replace((string) Html::text($xp, './/h1', $row), '', (string) preg_replace('/\s+/u', ' ', $cell->textContent))) : '';
                if ($url === null || $title === null || isset($seen[$url]) || preg_match(self::EXCLUDED, $title)) {
                    continue;
                }
                $range = $this->range($when, $today);
                if ($range === null) {
                    continue;
                }
                $seen[$url] = true;
                [$subcategory, $subtype] = $this->classify($title);

                yield new ScrapedEvent(
                    source: $this->name(),
                    externalId: trim((string) parse_url($url, \PHP_URL_PATH), '/'),
                    title: trim((string) preg_replace('/\s*-\s*PR[OÓ]XIMAMENTE$/iu', '', $title)),
                    start: $range[0],
                    end: $range[1]->setTime(23, 59),
                    city: $this->city(),
                    venueName: self::VENUE['name'],
                    latitude: self::VENUE['lat'],
                    longitude: self::VENUE['lng'],
                    link: $url,
                    imageUrl: Html::absolute(Html::attr($xp, './td/a/img', 'src', $row), self::SITE),
                    detailUrl: $url,
                    venueAddress: self::VENUE['address'],
                    subcategory: $subcategory,
                    subtype: $subtype,
                );
            }
        }
    }

    /**
     * La foto del calendario es un recorte de 200 px: el cartel de la ficha,
     * más grande, y su primer párrafo largo como texto.
     */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        $xp    = Html::xpath($html);
        $image = Html::absolute(Html::attr($xp, '//div[@itemprop="articleBody"]//img', 'src') ?? Html::attr($xp, '//div[' . Html::hasClass('item-page') . ']//img', 'src'), self::SITE);

        $description = null;
        foreach ($xp->query('//div[@itemprop="articleBody"]//p | //div[' . Html::hasClass('item-page') . ']//p') as $p) {
            $text = Html::clean($p->textContent, 400);
            // El primero suele ser la fecha y el aforo otra vez.
            if ($text !== null && mb_strlen($text) > 80 && !preg_match('/^(\d|lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bado|domingo)/iu', $text)) {
                $description = $text;
                break;
            }
        }

        return $event->withDetails($image, $description);
    }

    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue-casa-museo-lope-de-vega',
            name: self::VENUE['name'],
            city: $this->city(),
            categorySlug: 'tourism-museums',
            latitude: self::VENUE['lat'],
            longitude: self::VENUE['lng'],
            address: self::VENUE['address'],
            website: self::VENUE['website'],
        );
    }

    /**
     * Los calendarios que tocan los próximos 30 días, por el rótulo del menú
     * («Sep/Dic 2026»).
     *
     * @return list<string>
     */
    private function calendars(\DateTimeImmutable $today): array
    {
        $html = $this->web->get(self::SITE . '/es/actividades');
        if ($html === null) {
            return [];
        }

        $xp      = Html::xpath($html);
        $horizon = $today->modify('+30 days');
        $out     = [];

        foreach ($xp->query('//a[contains(@href, "/es/actividades/calendario/")]') as $a) {
            if (!$a instanceof \DOMElement || !preg_match('#^(\w{3})/(\w{3})\s+(\d{4})$#u', trim($a->textContent), $m)) {
                continue;
            }
            $from = SpanishDate::month($m[1]);
            $to   = SpanishDate::month($m[2]);
            if ($from === null || $to === null) {
                continue;
            }
            $first = $today->setDate((int) $m[3], $from, 1);
            $last  = $today->setDate((int) $m[3], $to, 1)->modify('last day of this month');
            if ($first <= $horizon && $last >= $today) {
                $out[(string) Html::absolute($a->getAttribute('href'), self::SITE)] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * «6 de octubre 2026», «Del 13 al 18 de octubre 2026», «Del 8 de octubre
     * al 8 de diciembre 2026», «Hasta el 13 de septiembre 2026». Sin día
     * («De junio a octubre», «De martes a domingo») no es una cita.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    private function range(string $when, \DateTimeImmutable $today): ?array
    {
        // «2 026»: el año partido por la maqueta.
        $when = mb_strtolower((string) preg_replace('/(\d)\s+(?=\d{2,})/u', '$1', $when));
        $year = preg_match('/\b(20\d{2})\b/', $when, $y) ? (int) $y[1] : null;

        if (preg_match('/del?\s+(\d{1,2})(?:\s+de\s+([a-záéíóú]+))?\s+al?\s+' . self::DATE . '/u', $when, $m)) {
            $end   = $this->date((int) $m[3], $m[4], $year, $today);
            $start = $end === null ? null : $this->date((int) $m[1], $m[2] !== '' ? $m[2] : $m[4], $year, $today);
            if ($start !== null && $start > $end) {
                // «Del 22 de diciembre al 19 de enero 2027»: el año es del fin.
                $start = $start->modify('-1 year');
            }

            return $start !== null && $end !== null ? [$start, $end] : null;
        }

        if (preg_match('/' . self::DATE . '/u', $when, $m) && ($day = $this->date((int) $m[1], $m[2], $year, $today)) !== null) {
            if (str_contains($when, 'hasta')) {
                return $day >= $today ? [$today, $day] : null;
            }

            return [$day, $day];
        }

        return null;
    }

    private function date(int $day, string $month, ?int $year, \DateTimeImmutable $today): ?\DateTimeImmutable
    {
        $n = SpanishDate::month($month);
        if ($n === null) {
            return null;
        }
        if ($year === null) {
            return SpanishDate::build($day, $n, null, $today);
        }

        return checkdate($n, $day, $year) ? $today->setDate($year, $n, $day) : null;
    }

    /** @return array{0: string, 1: ?string} */
    private function classify(string $title): array
    {
        $title = mb_strtolower($title);

        return match (true) {
            str_contains($title, 'exposici')                                  => ['events-art', 'events-art-temporary'],
            (bool) preg_match('/concierto|m[uú]sica|recital/u', $title)       => ['events-small-concerts', null],
            (bool) preg_match('/infantil|famili|niñ[oa]s|t[ií]teres/u', $title) => ['events-kids', 'events-kids-family-plans'],
            (bool) preg_match('/visita|ruta|madrid otra mirada/u', $title)    => ['events-experiences', 'events-experiences-guided-tours'],
            (bool) preg_match('/taller/u', $title)                            => ['events-experiences', 'events-experiences-workshops'],
            (bool) preg_match('/teatro|dramatizad|lectura/u', $title)         => ['events-stage', 'events-stage-theater'],
            default                                                           => ['events-other', null],
        };
    }
}
