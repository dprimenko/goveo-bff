<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Fundación Canal (fundacioncanal.com): sus exposiciones, en dos salas —Mateo
 * Inurria 2 y Castellana 214—.
 *
 * La página de exposiciones trae las actuales y las próximas en tarjetas con
 * sala, rango, cartel, texto y, si se paga, el enlace de reserva. Una exposición
 * es un evento con su rango, de día entero (el horario es el de apertura de la
 * sala).
 *
 * - Una exposición sin fin anunciado («28/10/2026 -») dura hasta 30 días después
 *   de hoy (o de su inicio), y cada pasada lo alarga, como en `teatro-la-latina`.
 * - Fuera la música de cámara y la de familias: sus conciertos son en domingo
 *   (la ventana es de jueves a sábado) y no tienen cartel propio; las
 *   conferencias tampoco entran.
 * - Las reservas de algunas exposiciones están en una web propia de la muestra
 *   (salgadoaquamater.com): es la del organizador, se enlaza como compra.
 */
final class FundacionCanalSource implements EventSource
{
    private const SITE    = 'https://www.fundacioncanal.com';
    private const LISTING = self::SITE . '/exposiciones/';

    /**
     * Salas por cómo las nombra la tarjeta (en minúsculas, sin el «Sala»).
     *
     * @var array<string, array{name: string, lat: float, lng: float, address: string}>
     */
    private const VENUES = [
        'mateo inurria' => [
            'name'    => 'Fundación Canal',
            'lat'     => 40.4659643,
            'lng'     => -3.6880179,
            'address' => 'Calle de Mateo Inurria, 2, 28036 Madrid',
        ],
        'castellana' => [
            'name'    => 'Fundación Canal Castellana 214',
            'lat'     => 40.4636363,
            'lng'     => -3.6889212,
            'address' => 'Paseo de la Castellana, 214, 28036 Madrid',
        ],
    ];

    /** Sin fin anunciado, días que se da por abierta (ver arriba). */
    private const OPEN_ENDED_DAYS = 30;

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'fundacion-canal';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::LISTING);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar las exposiciones de la Fundación Canal');
        }

        $xp     = Html::xpath($html);
        $events = [];

        foreach ($xp->query('//div[' . Html::hasClass('exposiciones_contenido') . ']') as $card) {
            $url   = Html::attr($xp, './/h2[' . Html::hasClass('categoria_titulo') . ']/a', 'href', $card);
            $title = Html::clean(Html::text($xp, './/h2[' . Html::hasClass('categoria_titulo') . ']', $card), 200);
            $range = $this->range(Html::text($xp, './/div[' . Html::hasClass('fecha') . ']', $card));
            $venue = $this->venue(Html::text($xp, './/a[' . Html::hasClass('fundacion') . ']', $card));
            $image = $this->image(Html::attr($xp, './/a[' . Html::hasClass('imagen_evento') . ']', 'style', $card));
            if ($url === null || $title === null || $range === null || $venue === null || isset($events[$url])) {
                continue;
            }

            $tickets = Html::attr($xp, './/a[' . Html::hasClass('entrada') . ']', 'href', $card);
            $about   = mb_strtolower($title . ' ' . Html::text($xp, './/div[' . Html::hasClass('texto_1') . ']', $card));

            $events[$url] = new ScrapedEvent(
                source: $this->name(),
                externalId: trim((string) parse_url($url, \PHP_URL_PATH), '/'),
                // En mayúsculas, como las escribe la web: pasarlas a minúsculas
                // se llevaría también las de los nombres («Doré», «Rauschenberg»).
                title: $title,
                start: $range[0],
                end: $range[1],
                city: $this->city(),
                venueName: $venue['name'],
                latitude: $venue['lat'],
                longitude: $venue['lng'],
                link: $tickets ?? $url,
                linkAction: $tickets !== null ? 'buy' : 'info',
                description: Html::clean(Html::text($xp, './/div[' . Html::hasClass('texto_1') . ']', $card)),
                imageUrl: $image,
                detailUrl: $url,
                venueAddress: $venue['address'],
                subcategory: 'events-art',
                subtype: preg_match('/fotograf|fotógraf/u', $about) ? 'events-art-photography' : 'events-art-temporary',
            );
        }

        return array_values($events);
    }

    /** Sin cartel en la tarjeta, el de la ficha para compartir. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->imageUrl !== null || $event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        return $event->withDetails(Html::attr(Html::xpath($html), '//meta[@property="og:image"]', 'content'), null);
    }

    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        foreach (self::VENUES as $key => $venue) {
            if ($venue['name'] === $event->venueName) {
                return new ScrapedVenue(
                    source: $this->name(),
                    externalId: 'venue-' . str_replace(' ', '-', $key),
                    name: $venue['name'],
                    city: $this->city(),
                    categorySlug: 'tourism-museums',
                    latitude: $venue['lat'],
                    longitude: $venue['lng'],
                    address: $venue['address'],
                    website: self::SITE . '/',
                );
            }
        }

        return null;
    }

    /** @return array{name: string, lat: float, lng: float, address: string}|null */
    private function venue(?string $room): ?array
    {
        $room = mb_strtolower((string) $room);
        foreach (self::VENUES as $key => $venue) {
            if (str_contains($room, $key)) {
                return $venue;
            }
        }

        return null;
    }

    /**
     * «07/10/2026 - 05/01/2027», o «28/10/2026 -» sin fin.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    private function range(?string $text): ?array
    {
        if ($text === null || !preg_match_all('#(\d{1,2})/(\d{1,2})/(\d{4})#', $text, $dates, \PREG_SET_ORDER)) {
            return null;
        }

        $tz  = new \DateTimeZone('Europe/Madrid');
        $out = [];
        foreach ($dates as $d) {
            if (!checkdate((int) $d[2], (int) $d[1], (int) $d[3])) {
                return null;
            }
            $out[] = (new \DateTimeImmutable('now', $tz))->setDate((int) $d[3], (int) $d[2], (int) $d[1])->setTime(0, 0);
        }

        $end = $out[1] ?? max($out[0], new \DateTimeImmutable('today', $tz))->modify(sprintf('+%d days', self::OPEN_ENDED_DAYS));

        return [$out[0], $end->setTime(23, 59)];
    }

    /** `background-image: url('…')` del cartel de la tarjeta. */
    private function image(?string $style): ?string
    {
        return $style !== null && preg_match("/url\\(['\"]?([^'\")]+)/", $style, $m) ? Html::absolute($m[1], self::SITE) : null;
    }
}
