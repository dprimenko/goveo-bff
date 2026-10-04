<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * MARPA, Museo Arqueológico y Paleontológico de la Comunidad de Madrid
 * (marpa.madrid), en Alcalá de Henares.
 *
 * **Está en Alcalá, no en Madrid**: el evento y la sala van con su municipio,
 * pero la fuente es de la agenda de Madrid (`city()`), como Fabrik.
 *
 * Dos listados: exposiciones temporales y «visitas guiadas y otras
 * actividades», con tarjetas «Del 09 DIC al 25 OCT 2026» (el año es sólo del
 * fin). El título de la tarjeta va recortado («…»): título, cartel y texto
 * salen de la ficha, que se abre sólo para lo que sigue vivo.
 *
 * Lo que dura meses en el listado de actividades (las visitas de los sábados
 * a una exposición, la visita a la colección hasta 2037) es una oferta fija,
 * no una cita: sólo entran las actividades de `MAX_ACTIVITY_DAYS` o menos.
 */
final class MarpaSource implements EventSource
{
    private const SITE = 'https://marpa.madrid';

    private const LISTINGS = [
        '/actividades/exposiciones-temporales'             => true,
        '/actividades/visitas-guiadas-y-otras-actividades' => false,
    ];

    private const MAX_ACTIVITY_DAYS = 31;

    private const EXCLUDED = '/conferencia|presentaci[oó]n del libro|curso|encuentro|coloquio|ceguera|discapacidad|permanente/iu';

    private const VENUE = [
        'name'    => 'Museo Arqueológico y Paleontológico de la Comunidad de Madrid',
        'lat'     => 40.482796,
        'lng'     => -3.369151,
        'address' => 'Plaza de las Bernardas, s/n, 28801 Alcalá de Henares',
        'website' => 'https://marpa.madrid/',
    ];

    private const MUNICIPALITY = 'Alcalá de Henares';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'marpa';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $tz    = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $tz);
        $read  = 0;
        $seen  = [];

        foreach (self::LISTINGS as $path => $exhibitions) {
            $html = $this->web->get(self::SITE . $path);
            if ($html === null) {
                continue;
            }
            ++$read;
            $xp = Html::xpath($html);

            foreach ($xp->query('//a[starts-with(@href, "/actividad/")][.//div[' . Html::hasClass('date-range') . ']]') as $card) {
                $url   = Html::absolute($card instanceof \DOMElement ? $card->getAttribute('href') : null, self::SITE);
                $range = $this->range((string) Html::text($xp, './/div[' . Html::hasClass('date-range') . ']', $card), $today, $exhibitions);
                $title = (string) Html::text($xp, './/div[' . Html::hasClass('title') . ']', $card);
                if ($url === null || $range === null || isset($seen[$url]) || $range[1] < $today || preg_match(self::EXCLUDED, $title)) {
                    continue;
                }
                if (!$exhibitions && $range[0]->diff($range[1])->days > self::MAX_ACTIVITY_DAYS) {
                    continue;
                }
                $seen[$url] = true;

                yield new ScrapedEvent(
                    source: $this->name(),
                    externalId: trim((string) parse_url($url, \PHP_URL_PATH), '/'),
                    title: $title,
                    start: $range[0],
                    end: $range[1]->setTime(23, 59),
                    city: self::MUNICIPALITY,
                    venueName: self::VENUE['name'],
                    latitude: self::VENUE['lat'],
                    longitude: self::VENUE['lng'],
                    link: $url,
                    imageUrl: Html::attr($xp, './/img', 'src', $card),
                    detailUrl: $url,
                    venueAddress: self::VENUE['address'],
                    subcategory: $exhibitions ? 'events-art' : $this->classify($title)[0],
                    subtype: $exhibitions ? 'events-art-temporary' : $this->classify($title)[1],
                );
            }
        }

        if ($read === 0) {
            throw new \RuntimeException('No se pudo leer la agenda del MARPA');
        }
    }

    /**
     * De la ficha: el título entero, el cartel (el de la galería, o la imagen
     * de cabecera) y el primer párrafo.
     */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        $xp    = Html::xpath($html);
        $title = Html::clean(Html::text($xp, '//h1'), 200);
        $image = Html::attr($xp, '//img[contains(@src, "/styles/colorbox_modal/")]', 'src')
            ?? Html::attr($xp, '//div[' . Html::hasClass('img_encabezado') . ']//img', 'src');
        $text  = Html::clean(Html::text($xp, '//div[' . Html::hasClass('field--name-body') . ']//p'), 400);

        $event = $event->withDetails($image, $text);
        if ($title === null || $title === $event->title) {
            return $event;
        }

        return new ScrapedEvent(
            source: $event->source,
            externalId: $event->externalId,
            title: $title,
            start: $event->start,
            end: $event->end,
            city: $event->city,
            venueName: $event->venueName,
            latitude: $event->latitude,
            longitude: $event->longitude,
            link: $event->link,
            linkAction: $event->linkAction,
            description: $event->description,
            imageUrl: $event->imageUrl,
            detailUrl: $event->detailUrl,
            weekdays: $event->weekdays,
            venueAddress: $event->venueAddress,
            subcategory: $event->subcategory,
            subtype: $event->subtype,
        );
    }

    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue-marpa',
            name: self::VENUE['name'],
            city: self::MUNICIPALITY,
            categorySlug: 'tourism-museums',
            latitude: self::VENUE['lat'],
            longitude: self::VENUE['lng'],
            address: self::VENUE['address'],
            website: self::VENUE['website'],
        );
    }

    /**
     * «Del 09 DIC al 25 OCT 2026»: el año es del fin, y un inicio posterior
     * al fin es del año anterior. «Del 01 OCT al 01 OCT 2027» en una
     * exposición es un año entero, no un día.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    private function range(string $text, \DateTimeImmutable $today, bool $exhibition): ?array
    {
        if (!preg_match('/del\s+(\d{1,2})\s+(\pL+)\s+al\s+(\d{1,2})\s+(\pL+)\s*(\d{4})/iu', $text, $m)) {
            return null;
        }
        $from = SpanishDate::month($m[2]);
        $to   = SpanishDate::month($m[4]);
        $year = (int) $m[5];
        if ($from === null || $to === null || !checkdate($to, (int) $m[3], $year)) {
            return null;
        }
        $end   = $today->setDate($year, $to, (int) $m[3]);
        $start = checkdate($from, (int) $m[1], $year) ? $today->setDate($year, $from, (int) $m[1]) : null;
        if ($start !== null && ($start > $end || ($exhibition && $start == $end))) {
            $start = checkdate($from, (int) $m[1], $year - 1) ? $start->setDate($year - 1, $from, (int) $m[1]) : null;
        }

        return $start === null ? null : [$start, $end];
    }

    /** @return array{0: string, 1: ?string} */
    private function classify(string $title): array
    {
        $title = mb_strtolower($title);

        return match (true) {
            (bool) preg_match('/infantil|famili|niñ[oa]s/u', $title)        => ['events-kids', str_contains($title, 'taller') ? 'events-kids-workshops' : 'events-kids-family-plans'],
            (bool) preg_match('/visita|ruta|itinerario/u', $title)          => ['events-experiences', 'events-experiences-guided-tours'],
            (bool) preg_match('/taller/u', $title)                          => ['events-experiences', 'events-experiences-workshops'],
            (bool) preg_match('/concierto|m[uú]sica/u', $title)             => ['events-small-concerts', null],
            (bool) preg_match('/danza/u', $title)                           => ['events-stage', 'events-stage-dance'],
            (bool) preg_match('/proyecci[oó]n|documental|cine/u', $title)   => ['events-experiences', 'events-experiences-cinema'],
            default                                                         => ['events-other', null],
        };
    }
}
