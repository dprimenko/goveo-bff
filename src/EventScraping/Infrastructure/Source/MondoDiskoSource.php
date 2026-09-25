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
 * Mondo Disko (mondodisko.es), club de techno y house: las noches de Sala But y
 * las tardes al aire libre (Mondo Open Air, Mondo Krystal) en Terraza Jowke.
 *
 * El listado de `/eventos/` trae todo lo que hace falta en la tarjeta —cartel,
 * line-up, sala, día con año y hora—, así que no se abren las fichas: la ficha
 * sólo añade las entradas, que se venden en la propia web.
 *
 * **Terraza Jowke está en Alcorcón**: sus eventos van con esa ciudad, para que
 * el dueño se busque allí y no se le cuelgue a un negocio de Madrid.
 */
final class MondoDiskoSource implements EventSource
{
    private const SITE    = 'https://www.mondodisko.es';
    private const LISTING = self::SITE . '/eventos/';

    /**
     * Sala tal como la escribe la web => [nombre en Goveo, ciudad, lat, lng,
     * dirección, web]. Las noches son de Mondo Disko aunque la sala sea But:
     * se le cuelgan al club, que es lo que la gente busca. Una sala que no
     * esté aquí se descarta —sin coordenadas no se sabría dónde enseñarla—.
     *
     * @var array<string, array{0: string, 1: string, 2: float, 3: float, 4: string, 5: ?string}>
     */
    private const VENUES = [
        'sala but'      => ['Mondo Disko', 'Madrid', 40.4270028, -3.6997019, 'Calle de Barceló, 11, 28004 Madrid', self::SITE . '/'],
        'terraza jowke' => ['Terraza Jowke', 'Alcorcón', 40.3576959, -3.835098, 'Av. San Martín de Valdeiglesias, 22, 28922 Alcorcón', null],
    ];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'mondo-disko';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::LISTING);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la agenda de Mondo Disko');
        }

        $xp     = Html::xpath($html);
        $events = [];

        foreach ($xp->query('//div[' . Html::hasClass('grid-evento') . ']') as $card) {
            $href   = Html::attr($xp, './/a[' . Html::hasClass('entradas-evento') . ']', 'href', $card);
            $lineup = Html::text($xp, './/*[' . Html::hasClass('artistas') . ']', $card);
            $tag    = Html::text($xp, './/*[' . Html::hasClass('tag-evento') . ']', $card);
            $img    = Html::attr($xp, './/*[' . Html::hasClass('flyer-evento') . ']//img', 'src', $card);
            $info   = [];
            foreach ($xp->query('.//*[' . Html::hasClass('fecha-evento') . ']/span', $card) as $span) {
                $info[] = trim($span->textContent);
            }

            // [sala, «Sáb. 26 SEP 2026», «23:59h»]
            $venue = self::VENUES[mb_strtolower($info[0] ?? '')] ?? null;
            $start = $this->start($info[1] ?? '', $info[2] ?? null);
            if ($href === null || $lineup === null || $venue === null || $start === null) {
                continue;
            }

            [$name, $city, $lat, $lng, $address] = $venue;
            $detail = Html::absolute($href, self::SITE);

            $events[] = new ScrapedEvent(
                source: $this->name(),
                externalId: basename(trim((string) parse_url((string) $detail, \PHP_URL_PATH), '/')),
                title: $this->title($lineup, $tag),
                start: $start,
                end: null,
                city: $city,
                venueName: $name,
                latitude: $lat,
                longitude: $lng,
                link: $detail,
                linkAction: 'buy',
                imageUrl: Html::absolute($img, self::SITE),
                detailUrl: $detail,
                venueAddress: $address,
                // Todo es techno y house, también las tardes al aire libre
                // (duran de 15 a 23 h: no son un tardeo de copas).
                subcategory: 'events-nightlife',
                subtype: 'events-nightlife-electronic',
            );
        }

        return Shows::group($events);
    }

    /** La ficha no tiene descripción ni otra imagen que la del listado. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        foreach (self::VENUES as $key => [$name, $city, $lat, $lng, $address, $website]) {
            if ($name === $event->venueName) {
                return new ScrapedVenue(
                    source: $this->name(),
                    externalId: 'venue-' . str_replace(' ', '-', $key),
                    name: $name,
                    city: $city,
                    categorySlug: 'nightlife',
                    latitude: $lat,
                    longitude: $lng,
                    address: $address,
                    website: $website,
                );
            }
        }

        return null;
    }

    /**
     * El título es el line-up («DJ FUCKOFF / GERARDO NIVA»): si no dice de qué
     * fiesta es, se le antepone la marca de la tarjeta («Mondo Disko · …»),
     * sin el «- HORARIO EXTENDIDO…» que a veces lleva.
     */
    private function title(string $lineup, ?string $tag): string
    {
        $brand = trim(explode(' - ', (string) $tag)[0]);
        if ($brand === '' || str_starts_with(mb_strtoupper($lineup), mb_strtoupper($brand)) || str_starts_with(mb_strtoupper($lineup), 'MONDO')) {
            return mb_substr($lineup, 0, 200);
        }

        return mb_substr(mb_convert_case($brand, \MB_CASE_TITLE) . ' · ' . $lineup, 0, 200);
    }

    /** «Sáb. 26 SEP 2026» y «23:59h»: el año viene escrito, no se deduce. */
    private function start(string $date, ?string $time): ?\DateTimeImmutable
    {
        if (!preg_match('/(\d{1,2})\s+(\p{L}+)\s+(\d{4})/u', $date, $m) || ($month = SpanishDate::month($m[2])) === null) {
            return null;
        }
        if (!checkdate($month, (int) $m[1], (int) $m[3])) {
            return null;
        }

        $day = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))
            ->setDate((int) $m[3], $month, (int) $m[1])
            ->setTime(0, 0);

        // 23:59 es la sesión de club que empieza a medianoche: se deja en el
        // día que anuncian, como en las demás salas.
        return $time !== null && preg_match('/(\d{1,2})[:.](\d{2})/', $time, $t)
            ? $day->setTime((int) $t[1], (int) $t[2])
            : $day;
    }
}
