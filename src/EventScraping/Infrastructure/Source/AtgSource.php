<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * ATG Entertainment: la cartelera de sus teatros de Madrid —Apolo, Nuevo
 * Alcalá (con su Sala 2), Rialto y Amaya—.
 *
 * Ni atgentertainment.es (sólo carteles que enlazan a la web de cada musical)
 * ni teatroalcala.es (su «Obras en cartel» es un enlace) tienen fechas: la
 * cartelera está en **atgtickets.es, la taquilla del propio grupo**, un
 * WordPress con los espectáculos en su API (`espectaculo`). Cada uno trae su
 * teatro (`acf.informacionGeneral.recinto`), el primer y el último día
 * (`acf.fechaRepree`), el horario en texto libre y el cartel (un id de la
 * biblioteca de medios, que se pide aparte y de una vez).
 *
 * No hay funciones sueltas: cada espectáculo dura su rango, como en el
 * Calderón. Del horario («Martes, miércoles y jueves a las 19:45; viernes y
 * sábado…», «Domingos seleccionados a las 12:00») se sacan los días de la
 * semana, para que el filtro de jueves a sábado no cuele lo que es sólo de
 * domingo; y si es una hora sola («20:00»), la hora de inicio.
 */
final class AtgSource implements EventSource
{
    private const API = 'https://atgtickets.es/wp-json/wp/v2';

    /** Por el id del recinto en la taquilla. Uno que no esté aquí no es de Madrid. */
    private const VENUES = [
        71 => [
            'name'    => 'Nuevo Teatro Alcalá',
            'lat'     => 40.4233969,
            'lng'     => -3.6782462,
            'address' => 'Calle de Jorge Juan, 62, 28009 Madrid',
            'website' => 'https://www.teatroalcala.es/',
        ],
        // La sala pequeña del mismo teatro: el mismo negocio.
        145 => [
            'name'    => 'Nuevo Teatro Alcalá',
            'lat'     => 40.4233969,
            'lng'     => -3.6782462,
            'address' => 'Calle de Jorge Juan, 62, 28009 Madrid',
            'website' => 'https://www.teatroalcala.es/',
        ],
        79 => [
            'name'    => 'Teatro Apolo',
            'lat'     => 40.412189,
            'lng'     => -3.7033341,
            'address' => 'Plaza de Tirso de Molina, 1, 28012 Madrid',
            'website' => 'https://atgentertainment.es/espacio/teatro-nuevo-apolo/',
        ],
        146 => [
            'name'    => 'Teatro Rialto',
            'lat'     => 40.421345,
            'lng'     => -3.707231,
            'address' => 'Gran Vía, 54, 28013 Madrid',
            'website' => 'https://atgentertainment.es/espacio/teatro-rialto/',
        ],
        3464 => [
            'name'    => 'Teatro Amaya',
            'lat'     => 40.4351383,
            'lng'     => -3.6971411,
            'address' => 'Paseo del General Martínez Campos, 9, 28010 Madrid',
            'website' => 'https://atgentertainment.es/espacio/teatro-amaya/',
        ],
    ];

    /** Categoría de la taquilla → tipo y subnivel, la primera que case. */
    private const TYPES = [
        'musicales'      => ['events-stage', 'events-stage-musicals'],
        'teatro-musical' => ['events-stage', 'events-stage-musicals'],
        'magia'          => ['events-stage', 'events-stage-magic'],
        'danza'          => ['events-stage', 'events-stage-dance'],
        'comedia'        => ['events-stage', 'events-stage-comedy'],
        'monologo'       => ['events-stage', 'events-stage-comedy'],
        'conciertos'     => ['events-small-concerts', null],
    ];

    private const WEEKDAYS = [
        'lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7,
    ];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'atg';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $shows = [];
        for ($page = 1; $page <= 5; ++$page) {
            $json  = $this->web->get(self::API . '/espectaculo?per_page=100&page=' . $page);
            $batch = $json !== null ? json_decode($json, true) : null;
            if (!is_array($batch)) {
                if ($page === 1) {
                    throw new \RuntimeException('No se pudo descargar la cartelera de ATG');
                }
                break;
            }
            array_push($shows, ...$batch);
            if (count($batch) < 100) {
                break;
            }
        }

        $shows   = array_filter($shows, fn ($s) => is_array($s) && isset(self::VENUES[$this->venueId($s)]));
        $posters = $this->posters(array_map(fn (array $s) => (int) ($s['acf']['cartel'] ?? 0), $shows));
        $tz      = new \DateTimeZone('Europe/Madrid');

        foreach ($shows as $show) {
            $acf   = $show['acf'];
            $venue = self::VENUES[$this->venueId($show)];
            $title = Html::clean($show['title']['rendered'] ?? null, 200);
            $image = $posters[(int) ($acf['cartel'] ?? 0)] ?? null;
            $first = \DateTimeImmutable::createFromFormat('!Ymd', (string) ($acf['fechaRepree']['diaComienzo'] ?? ''), $tz);
            $last  = \DateTimeImmutable::createFromFormat('!Ymd', (string) ($acf['fechaRepree']['diaFinal'] ?? ''), $tz) ?: $first;
            if ($title === null || $image === null || $first === false || $last === false) {
                continue;
            }

            $schedule = (string) ($acf['fechaRepree']['horarioRepresentacion'] ?? '');
            // Una hora sola («20:00») es la de todas las funciones.
            $start = preg_match('/^\s*(\d{1,2})[:.](\d{2})\s*h?\.?\s*$/i', $schedule, $t)
                ? $first->setTime((int) $t[1], (int) $t[2])
                : $first;
            $operator                = $acf['precio']['urlOperador'] ?? null;
            $tickets                 = is_string($operator) && preg_match('#^https?://#', $operator) ? $operator : null;
            $page                    = is_string($show['link'] ?? null) ? $show['link'] : null;
            [$subcategory, $subtype] = $this->classify((array) ($show['class_list'] ?? []));

            yield new ScrapedEvent(
                source: $this->name(),
                externalId: (string) ($show['slug'] ?? $show['id']),
                title: $title,
                start: $start,
                end: $last->setTime(23, 59),
                city: $this->city(),
                venueName: $venue['name'],
                latitude: $venue['lat'],
                longitude: $venue['lng'],
                link: $tickets ?? $page,
                linkAction: $tickets !== null ? 'buy' : 'info',
                description: Html::clean($acf['sinopsis'] ?? null, 400) ?? Html::clean($acf['subtitulo'] ?? null, 400),
                imageUrl: $image,
                detailUrl: $page,
                weekdays: $this->weekdays($schedule),
                venueAddress: $venue['address'],
                subcategory: $subcategory,
                subtype: $subtype,
            );
        }
    }

    /** La API ya lo trae todo. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        foreach (self::VENUES as $venue) {
            if ($venue['name'] !== $event->venueName) {
                continue;
            }

            return new ScrapedVenue(
                source: $this->name(),
                externalId: 'venue-' . trim((string) preg_replace('/[^a-z0-9]+/', '-', strtr(mb_strtolower($venue['name']), ['á' => 'a'])), '-'),
                name: $venue['name'],
                city: $this->city(),
                categorySlug: 'culture-business',
                latitude: $venue['lat'],
                longitude: $venue['lng'],
                address: $venue['address'],
                website: $venue['website'],
            );
        }

        return null;
    }

    /** @param array<string, mixed> $show */
    private function venueId(array $show): int
    {
        $info = $show['acf']['informacionGeneral'] ?? null;

        return is_array($info) ? (int) (((array) ($info['recinto'] ?? []))[0] ?? 0) : 0;
    }

    /**
     * Las URLs de los carteles, de una petición a la biblioteca de medios.
     *
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private function posters(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        $out = [];
        foreach (array_chunk($ids, 100) as $chunk) {
            $json  = $this->web->get(self::API . '/media?per_page=100&include=' . implode(',', $chunk));
            $media = $json !== null ? json_decode($json, true) : null;
            foreach (is_array($media) ? $media : [] as $m) {
                if (is_array($m) && is_string($m['source_url'] ?? null)) {
                    $out[(int) $m['id']] = $m['source_url'];
                }
            }
        }

        return $out;
    }

    /**
     * Los días de función que nombra el horario: «martes a sábados», «viernes,
     * sábado y domingo», «domingos seleccionados». Nulo si no nombra ninguno
     * («20:00», «Varios horarios»): entonces cuenta todo el rango.
     *
     * @return list<int>|null
     */
    private function weekdays(string $schedule): ?array
    {
        $text = strtr(mb_strtolower($schedule), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        $days = [];

        $names = implode('|', array_keys(self::WEEKDAYS));
        if (preg_match_all('/\b(' . $names . ')s?\s+a\s+(' . $names . ')s?\b/u', $text, $ranges, \PREG_SET_ORDER)) {
            foreach ($ranges as $r) {
                $from = self::WEEKDAYS[$r[1]];
                $to   = self::WEEKDAYS[$r[2]];
                for ($d = $from; ; $d = $d % 7 + 1) {
                    $days[$d] = true;
                    if ($d === $to) {
                        break;
                    }
                }
            }
        }
        if (preg_match_all('/\b(' . $names . ')s?\b/u', $text, $single)) {
            foreach ($single[1] as $name) {
                $days[self::WEEKDAYS[$name]] = true;
            }
        }
        if ($days === []) {
            return null;
        }
        $days = array_keys($days);
        sort($days);

        return $days;
    }

    /**
     * @param list<string> $classes `category-musicales`, `category-comedia`…
     *
     * @return array{0: string, 1: ?string}
     */
    private function classify(array $classes): array
    {
        foreach (self::TYPES as $category => $type) {
            if (in_array('category-' . $category, $classes, true)) {
                return $type;
            }
        }

        return ['events-stage', 'events-stage-theater'];
    }
}
