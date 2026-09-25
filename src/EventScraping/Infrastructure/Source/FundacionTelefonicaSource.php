<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\JsonLd;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Espacio Fundación Telefónica: exposiciones, talleres y el cine.
 *
 * Es WordPress con «The Events Calendar», pero **la API de Tribe está cerrada**
 * (401, «solo los usuarios identificados pueden acceder a la API REST») y por
 * eso no sirve `TribeEventsSource`. Lo que sí publica el plugin es su
 * calendario iCal (`/events/?ical=1`), que es la misma agenda con fechas,
 * categorías, enlace e imagen. Se lee eso.
 *
 * Lo que no entra: los encuentros y charlas (la mayor parte de «Actividades»),
 * los talleres de alfabetización digital para mayores (RECONECTADOS) y lo de
 * estudiantes. El calendario no trae descripción: sale de la ficha.
 */
final class FundacionTelefonicaSource implements EventSource
{
    private const SITE = 'https://espacio.fundaciontelefonica.com';

    private const VENUE = [
        'name'    => 'Espacio Fundación Telefónica',
        'lat'     => 40.4203920,
        'lng'     => -3.7017747,
        'address' => 'Calle de Fuencarral, 3, 28004 Madrid',
    ];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'fundacion-telefonica';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $ics = $this->web->get(self::SITE . '/events/?ical=1');
        if ($ics === null || !str_contains($ics, 'BEGIN:VCALENDAR')) {
            throw new \RuntimeException('No se pudo leer el calendario de Espacio Fundación Telefónica');
        }

        foreach ($this->entries($ics) as $e) {
            $title = Html::clean($e['SUMMARY'] ?? null, 200);
            $start = $this->date($e['DTSTART'] ?? null);
            $url   = $e['URL'] ?? null;
            $image = $e['ATTACH'] ?? null;
            if ($title === null || $start === null || $url === null) {
                continue;
            }

            $categories = array_map('trim', explode(',', mb_strtolower($e['CATEGORIES'] ?? '')));
            $type       = $this->classify($title, $categories);
            if ($type === null) {
                continue;
            }

            $end = $this->date($e['DTEND'] ?? null);
            // Un día entero en iCal acaba al empezar el siguiente: el último día
            // de la exposición es el anterior.
            if ($end !== null && preg_match('/^\d{8}$/', (string) $e['DTEND'])) {
                $end = $end->modify('-1 day')->setTime(23, 59);
            }

            $description = null;
            // La exposición se abre ya aquí (son dos o tres): el subnivel
            // —fotografía, inmersiva— sólo lo dice su presentación.
            if ($type[0] === 'events-art') {
                $description = $this->summary($url);
                $about       = mb_strtolower($title . ' ' . $description);
                $type[1]     = match (true) {
                    (bool) preg_match('/inmersiv|realidad virtual/u', $about) => 'events-art-immersive',
                    (bool) preg_match('/fotograf|fotógraf|photoespaña/u', $about) => 'events-art-photography',
                    default                                                  => 'events-art-temporary',
                };
            }

            yield new ScrapedEvent(
                source: $this->name(),
                // `UID` es «idDelPost-inicio-fin@dominio»: el post es lo estable.
                externalId: (string) strtok((string) ($e['UID'] ?? md5($url)), '-'),
                title: $title,
                start: $start,
                end: $end !== null && $end > $start ? $end : null,
                city: $this->city(),
                venueName: self::VENUE['name'],
                latitude: self::VENUE['lat'],
                longitude: self::VENUE['lng'],
                link: $url,
                linkAction: 'info',
                description: $description,
                imageUrl: is_string($image) && preg_match('#^https?://#', $image) ? $image : null,
                detailUrl: $url,
                venueAddress: self::VENUE['address'],
                subcategory: $type[0],
                subtype: $type[1],
            );
        }
    }

    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->description !== null || $event->detailUrl === null) {
            return $event;
        }

        return $event->withDetails(null, $this->summary($event->detailUrl));
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: self::VENUE['name'],
            city: $this->city(),
            categorySlug: 'culture-business',
            latitude: self::VENUE['lat'],
            longitude: self::VENUE['lng'],
            address: self::VENUE['address'],
            website: self::SITE . '/',
        );
    }

    /**
     * Tipo y subnivel por las categorías del calendario. «Actividades» son sobre
     * todo encuentros y charlas: sólo entra lo que por el título es cine,
     * música o una visita. «Todopoderosos» es su ciclo de charlas de cine.
     *
     * @param list<string> $categories
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function classify(string $title, array $categories): ?array
    {
        $title = mb_strtolower($title);

        return match (true) {
            in_array('exposición', $categories, true) => ['events-art', 'events-art-temporary'],
            in_array('taller', $categories, true)     => in_array('seniors', $categories, true) || preg_match('/estudiantes|bachillerato|escolar/u', $title)
                ? null
                : ['events-experiences', 'events-experiences-workshops'],
            (bool) preg_match('/\bcine\b|proyecci|película|todopoderosos/u', $title) => ['events-experiences', 'events-experiences-cinema'],
            (bool) preg_match('/concierto|música/u', $title)                         => ['events-small-concerts', null],
            (bool) preg_match('/visita/u', $title)                                   => ['events-experiences', 'events-experiences-guided-tours'],
            default                                   => null,
        };
    }

    /**
     * La presentación de la ficha: la de sus datos estructurados y, si está
     * vacía, el `og:description`. Éste va repetido —el primero es el de la web
     * entera, «Fundación Telefónica»—, así que se toma el último y sólo si dice
     * algo.
     */
    private function summary(string $url): ?string
    {
        $html = $this->web->get($url);
        if ($html === null) {
            return null;
        }

        // Tribe deja los saltos de línea escapados dos veces: llegan como `\n` literal.
        $ld = Html::clean(str_replace('\\n', ' ', (string) JsonLd::text(JsonLd::events($html)[0]['description'] ?? null, 'text')));
        $og = Html::clean(Html::attr(Html::xpath($html), '(//meta[@property="og:description"])[last()]', 'content'));

        return $ld ?? ($og !== null && mb_strlen($og) > 40 ? $og : null);
    }

    /**
     * Los `VEVENT` del calendario como `CLAVE => valor`, sin los parámetros
     * (`DTSTART;TZID=Europe/Madrid` → `DTSTART`).
     *
     * @return list<array<string, string>>
     */
    private function entries(string $ics): array
    {
        // Las líneas largas siguen en la siguiente, empezando por un espacio.
        $ics     = (string) preg_replace('/\r?\n[ \t]/', '', $ics);
        $entries = [];
        $current = null;

        foreach (preg_split('/\r?\n/', $ics) ?: [] as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $current = [];
            } elseif ($line === 'END:VEVENT' && $current !== null) {
                $entries[] = $current;
                $current   = null;
            } elseif ($current !== null && preg_match('/^([A-Z-]+)(?:;[^:]*)?:(.*)$/', $line, $m)) {
                $current[$m[1]] ??= strtr($m[2], ['\\,' => ',', '\\;' => ';', '\\n' => "\n", '\\N' => "\n", '\\\\' => '\\']);
            }
        }

        return $entries;
    }

    /** `20261006T190000` (hora de Madrid, la del `TZID`) o `20261117` (día entero). */
    private function date(?string $raw): ?\DateTimeImmutable
    {
        $tz = new \DateTimeZone('Europe/Madrid');

        return match (true) {
            $raw === null                          => null,
            (bool) preg_match('/^\d{8}$/', $raw)   => \DateTimeImmutable::createFromFormat('!Ymd', $raw, $tz) ?: null,
            (bool) preg_match('/^\d{8}T\d{6}$/', $raw) => \DateTimeImmutable::createFromFormat('Ymd\THis', $raw, $tz) ?: null,
            default                                => null,
        };
    }
}
