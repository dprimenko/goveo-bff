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
 * El Sótano (salaelsotano.com), sala de conciertos y club en La Latina.
 *
 * Su página de entradas lista todo lo que viene en tarjetas —cartel, título,
 * «VIE. 25/09/2026 - 20:00h» y el botón de compra—, conciertos
 * (`conciertosType`) y club (`clubType`). No se abren las fichas: la del evento
 * no tiene la fecha, y la API de WordPress (`/wp-json/wp/v2/evento`) tampoco.
 *
 * - La página repite cada tarjeta en tres bloques (todo, conciertos, club), y a
 *   veces la misma dos veces en uno: se quita lo repetido antes de juntar.
 * - Las entradas se venden fuera (RA, DICE, Entradium…): el botón es la compra.
 * - El título es la fiesta y el cartel («HOUSENATION: Ismael Rivas, …»): una
 *   fiesta que repite título entero es una (ver `Shows`); con otro cartel es
 *   otra noche, como en Mondo Disko.
 */
final class ElSotanoSource implements EventSource
{
    private const SITE    = 'https://www.salaelsotano.com';
    private const LISTING = self::SITE . '/entradas/';
    private const NAME    = 'El Sótano';
    private const LAT     = 40.4110358;
    private const LNG     = -3.7077269;
    private const ADDRESS = 'Calle de las Maldonadas, 6, 28005 Madrid';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'el-sotano';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::LISTING);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la agenda de El Sótano');
        }

        $xp     = Html::xpath($html);
        $events = [];

        foreach ($xp->query('//div[' . Html::hasClass('evento-club-item') . ']') as $card) {
            if (!$card instanceof \DOMElement) {
                continue;
            }
            $title = Html::clean(Html::text($xp, './/h3', $card), 200);
            $start = $this->start(Html::text($xp, './/*[' . Html::hasClass('evento-club-fecha') . ']', $card));
            $image = $this->image(Html::attr($xp, './/img', 'srcset', $card), Html::attr($xp, './/img', 'src', $card));
            if ($title === null || $start === null || $image === null) {
                continue;
            }

            $id = $this->slug($title);
            // Repetida en otro bloque de la página.
            if (isset($events[$id . '@' . $start->format('c')])) {
                continue;
            }

            $tickets = Html::attr($xp, './/a[' . Html::hasClass('evento-club-boton') . ']', 'href', $card);
            [$subcategory, $subtype] = $this->classify($card->getAttribute('class'), $start);

            $events[$id . '@' . $start->format('c')] = new ScrapedEvent(
                source: $this->name(),
                externalId: $id,
                title: $title,
                start: $start,
                // Sin hora («VIE. 23/10/2026»), dura el día entero.
                end: $start->format('H:i') === '00:00' ? $start->setTime(23, 59) : null,
                city: $this->city(),
                venueName: self::NAME,
                latitude: self::LAT,
                longitude: self::LNG,
                link: $tickets ?? self::LISTING,
                linkAction: $tickets !== null ? 'buy' : 'info',
                imageUrl: $image,
                venueAddress: self::ADDRESS,
                subcategory: $subcategory,
                subtype: $subtype,
            );
        }

        return Shows::group(array_values($events));
    }

    /** El listado ya lo trae todo; la ficha no añade descripción útil. */
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
            categorySlug: 'nightlife',
            latitude: self::LAT,
            longitude: self::LNG,
            address: self::ADDRESS,
            website: self::SITE . '/',
        );
    }

    /**
     * Conciertos, y el club: las sesiones de tarde (18 h, «AVISPERO», «BOSSA»)
     * son tardeo; las de medianoche, electrónica —todo lo que pincha la sala
     * está en Resident Advisor—.
     *
     * @return array{0: string, 1: ?string}
     */
    private function classify(string $class, \DateTimeImmutable $start): array
    {
        if (!str_contains($class, 'clubType')) {
            return ['events-small-concerts', null];
        }

        return (int) $start->format('G') < 21
            ? ['events-nightlife', 'events-nightlife-tardeo']
            : ['events-nightlife', 'events-nightlife-electronic'];
    }

    /**
     * «VIE. 25/09/2026 - 20:00h», con el año escrito; a veces sin hora.
     * «23:59h» es la sesión de medianoche y se deja en el día que anuncian,
     * como en las demás salas.
     */
    private function start(?string $text): ?\DateTimeImmutable
    {
        if ($text === null || !preg_match('#(\d{1,2})/(\d{1,2})/(\d{4})#', $text, $d) || !checkdate((int) $d[2], (int) $d[1], (int) $d[3])) {
            return null;
        }

        $day = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))
            ->setDate((int) $d[3], (int) $d[2], (int) $d[1])
            ->setTime(0, 0);

        return preg_match('/-\s*(\d{1,2})[:.](\d{2})/', $text, $t) ? $day->setTime((int) $t[1], (int) $t[2]) : $day;
    }

    /** El cartel más grande del `srcset` (la miniatura es de 240 px). */
    private function image(?string $srcset, ?string $src): ?string
    {
        $best  = $src;
        $width = 0;
        foreach (explode(',', (string) $srcset) as $candidate) {
            if (preg_match('/^\s*(\S+)\s+(\d+)w\s*$/', $candidate, $m) && (int) $m[2] > $width) {
                $best  = $m[1];
                $width = (int) $m[2];
            }
        }

        return Html::absolute($best, self::SITE);
    }

    private function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return mb_substr(trim(preg_replace('/[^a-z0-9]+/', '-', $text) ?? '', '-'), 0, 200);
    }
}
