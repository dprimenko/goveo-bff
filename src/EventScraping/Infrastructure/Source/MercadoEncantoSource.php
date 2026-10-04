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
 * Mercado del Encanto (mercadodelencanto.es), mercadillo itinerante de marcas
 * pequeñas, diseño y artesanía, por la API de su calendario de WordPress (The
 * Events Calendar, como `TribeEventsSource`; no se usa la base porque aquí cada
 * edición va en un sitio distinto).
 *
 * - **Sólo las ediciones de Madrid**: el mismo calendario lleva Cartagena,
 *   Logroño, Haro… Se reconoce por la ciudad del recinto.
 * - **El recinto cambia** (Palacio de Santa Bárbara, Hipódromo de la Zarzuela,
 *   Espacio Out…): las coordenadas de los conocidos van en `VENUES`; uno nuevo
 *   sale con su dirección y sin coordenadas, y hay que apuntarlo aquí.
 * - El evento es un rango de días enteros, pero las del Hipódromo son **sólo
 *   unos domingos sueltos** («Días: 18 octubre y 22 de noviembre – Domingos de
 *   10:30 a 15H»): con esa línea se sacan los pases de verdad y se juntan en un
 *   evento (`Shows`). Sin ella, el rango tal cual (las de viernes a domingo).
 * - La descripción de la web es para los expositores («Convocatoria abierta a
 *   partir del…»): ésa no se usa.
 * - Sin sala propia: el evento va a nombre del recinto, que es donde hay que ir
 *   (y si ya está en Goveo, es suyo; si no, a la Agenda).
 */
final class MercadoEncantoSource implements EventSource
{
    private const SITE = 'https://mercadodelencanto.es';

    /** Recintos de Madrid que ha usado, por su nombre en la web. */
    private const VENUES = [
        'palacio de santa bárbara'     => [40.4263418, -3.6972008],
        'hipódromo de la zarzuela'     => [40.4676988, -3.7598056],
        'espacio out'                  => [40.4299717, -3.7032334],
        'centro cultural los ejércitos' => [40.4196574, -3.699839],
        'noches del botánico'          => [40.4483832, -3.7276368],
    ];

    private const MONTHS = 'enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'mercado-encanto';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $tz  = new \DateTimeZone('Europe/Madrid');
        $raw = $this->web->get(sprintf(
            '%s/wp-json/tribe/events/v1/events?start_date=%s&per_page=50',
            self::SITE,
            (new \DateTimeImmutable('today', $tz))->format('Y-m-d'),
        ));
        $data = $raw !== null ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_array($data['events'] ?? null)) {
            throw new \RuntimeException('No se pudo leer la agenda del Mercado del Encanto');
        }

        $passes = [];
        foreach ($data['events'] as $e) {
            $venue = is_array($e['venue'] ?? null) ? $e['venue'] : [];
            if (mb_strtolower(trim((string) ($venue['city'] ?? ''))) !== 'madrid') {
                continue;
            }

            $start = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($e['start_date'] ?? ''), $tz) ?: null;
            $end   = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($e['end_date'] ?? ''), $tz) ?: null;
            $image = is_string($e['image']['url'] ?? null) ? $e['image']['url'] : null;
            $url   = is_string($e['url'] ?? null) ? $e['url'] : null;
            if ($start === null || $url === null) {
                continue;
            }

            $place   = Html::clean($venue['venue'] ?? null, 120) ?? 'Mercado del Encanto';
            $coords  = self::VENUES[mb_strtolower($place)] ?? [null, null];
            $address = implode(', ', array_filter([Html::clean($venue['address'] ?? null), trim(($venue['zip'] ?? '') . ' Madrid')]));
            $text    = Html::clean($e['description'] ?? null) ?? '';
            $days    = str_contains(mb_strtolower($text), 'días:') ? $this->days($text, $start) : [];

            $base = new ScrapedEvent(
                source: $this->name(),
                externalId: (string) ($e['id'] ?? basename(trim((string) parse_url($url, \PHP_URL_PATH), '/'))),
                title: 'Mercado del Encanto · ' . $place,
                start: $start,
                end: ($end ?? $start)->setTime(23, 59),
                city: $this->city(),
                venueName: $place,
                latitude: $coords[0],
                longitude: $coords[1],
                link: $url,
                linkAction: 'info',
                // Lo de los expositores no; los días y el horario, sí.
                description: str_contains(mb_strtolower($text), 'convocatoria') ? null : ($text !== '' ? $text : null),
                imageUrl: $image,
                detailUrl: $url,
                venueAddress: $address !== '' ? $address : null,
                subcategory: 'events-markets',
                subtype: 'events-markets-vintage-crafts',
            );

            if ($days === []) {
                $passes[] = $base;
                continue;
            }

            [$from, $to] = $this->hours($text);
            foreach ($days as $day) {
                $passes[] = new ScrapedEvent(
                    source: $base->source,
                    externalId: $base->externalId,
                    title: $base->title,
                    start: $day->setTime($from[0], $from[1]),
                    end: $to !== null ? $day->setTime($to[0], $to[1]) : $day->setTime(23, 59),
                    city: $base->city,
                    venueName: $base->venueName,
                    latitude: $base->latitude,
                    longitude: $base->longitude,
                    link: $base->link,
                    linkAction: $base->linkAction,
                    description: $base->description,
                    imageUrl: $base->imageUrl,
                    detailUrl: $base->detailUrl,
                    venueAddress: $base->venueAddress,
                    subcategory: $base->subcategory,
                    subtype: $base->subtype,
                );
            }
        }

        return Shows::group($passes);
    }

    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    /** El recinto no es del mercado: no se crea ficha (ver arriba). */
    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        return null;
    }

    /**
     * «Días: 23 de marzo /6 de abril / 4 y 11 de mayo» → las fechas, con el año
     * del inicio del evento (o el siguiente, si el mes es anterior).
     *
     * @return list<\DateTimeImmutable>
     */
    private function days(string $text, \DateTimeImmutable $start): array
    {
        $text = mb_substr($text, mb_stripos($text, 'días:') + 5);
        preg_match_all('/((?:\d{1,2}\s*(?:,|y)?\s*)+)(?:de\s+)?(' . self::MONTHS . ')/iu', $text, $all, \PREG_SET_ORDER);

        $days = [];
        foreach ($all as [, $numbers, $monthName]) {
            $month = SpanishDate::month($monthName);
            $year  = (int) $start->format('Y') + ($month < (int) $start->format('n') ? 1 : 0);
            preg_match_all('/\d{1,2}/', $numbers, $n);
            foreach ($n[0] as $d) {
                if ($month !== null && checkdate($month, (int) $d, $year)) {
                    $days[] = $start->setDate($year, $month, (int) $d)->setTime(0, 0);
                }
            }
        }

        return $days;
    }

    /**
     * «de 10:30 a 15H» / «de 11 a 15H».
     *
     * @return array{0: array{0: int, 1: int}, 1: ?array{0: int, 1: int}}
     */
    private function hours(string $text): array
    {
        if (!preg_match('/de\s+(\d{1,2})(?:[:.](\d{2}))?\s*h?\s*a\s+(\d{1,2})(?:[:.](\d{2}))?\s*h/iu', $text, $m)) {
            return [[0, 0], null];
        }

        return [[(int) $m[1], (int) ($m[2] ?: 0)], [(int) $m[3], (int) (($m[4] ?? '') ?: 0)]];
    }
}
