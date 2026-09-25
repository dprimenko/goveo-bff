<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Sala Siroco (siroco.es), por el calendario iCal que publica su plugin de
 * agenda (Events Manager, `/events.ics`): toda la programación futura en un
 * fichero, con fecha y hora, cartel, enlace a la ficha y el tipo que le pone la
 * sala (Conciertos, Clubbing, Fast expos). Leerlo evita la maqueta de
 * Elementor, que cambia más que el plugin.
 *
 * Las entradas (DICE) no vienen en el calendario: salen de la ficha, y sólo de
 * lo que ya pasó el filtro de fechas.
 */
final class SirocoSource implements EventSource
{
    private const BASE = 'https://siroco.es';
    private const LAT  = 40.4269445;
    private const LNG  = -3.7077564;

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'siroco';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $ics = $this->web->get(self::BASE . '/events.ics');
        if ($ics === null || !str_contains($ics, 'BEGIN:VCALENDAR')) {
            throw new \RuntimeException('No se pudo leer el calendario de Siroco');
        }

        $tz = new \DateTimeZone('Europe/Madrid');

        foreach ($this->vevents($ics) as $e) {
            $title = Html::clean($e['SUMMARY'] ?? null, 200);
            $start = $this->date($e['DTSTART'] ?? null, $tz);
            $url   = $e['URL'] ?? null;
            if ($title === null || $start === null || $url === null) {
                continue;
            }

            $end = $this->date($e['DTEND'] ?? null, $tz);
            // Las sesiones de club vienen «de 00:00 del viernes a 06:00 del
            // sábado»: es la noche del viernes. Se deja a las 23:59 del día que
            // anuncian, como el resto de salas (ver `SpanishDate`), y no a la
            // madrugada anterior.
            if ($end !== null && $start->format('H:i') === '00:00' && $end->format('Y-m-d') > $start->format('Y-m-d')) {
                $start = $start->setTime(23, 59);
            }
            if ($end !== null && $end <= $start) {
                $end = null;
            }

            [$subcategory, $subtype] = $this->classify(mb_strtolower($e['CATEGORIES'] ?? ''));

            yield new ScrapedEvent(
                source: $this->name(),
                externalId: strtok($e['UID'] ?? md5($url), '@'),
                title: $title,
                start: $start,
                end: $end,
                city: $this->city(),
                venueName: 'Siroco',
                latitude: self::LAT,
                longitude: self::LNG,
                link: $url,
                linkAction: 'info',
                description: Html::clean($e['DESCRIPTION'] ?? null),
                imageUrl: $e['ATTACH'] ?? null,
                detailUrl: $url,
                venueAddress: 'Calle de San Dimas, 3, 28015 Madrid',
                subcategory: $subcategory,
                subtype: $subtype,
            );
        }
    }

    /** El enlace de entradas de la ficha, si lo tiene; si no, la ficha. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        $tickets = Html::attr(Html::xpath($html), '//a[contains(@href, "dice.fm")]', 'href');

        return $tickets !== null ? $event->withDetails(null, null, $tickets, 'buy') : $event;
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: 'Siroco',
            city: $this->city(),
            categorySlug: 'nightlife',
            latitude: self::LAT,
            longitude: self::LNG,
            address: 'Calle de San Dimas, 3, 28015 Madrid',
            website: self::BASE . '/',
        );
    }

    /**
     * El tipo lo pone la sala: el clubbing es noche, las «Fast expos» son
     * exposiciones de una noche en su ArtLab, y el resto, conciertos.
     *
     * @return array{0: string, 1: ?string}
     */
    private function classify(string $category): array
    {
        return match (true) {
            str_contains($category, 'clubbing') => ['events-nightlife', 'events-nightlife-dj-sessions'],
            str_contains($category, 'expo')     => ['events-art', null],
            default                             => ['events-small-concerts', null],
        };
    }

    /**
     * Los `VEVENT` del fichero como `propiedad => valor`, sin parámetros
     * (`DTSTART;TZID=…` → `DTSTART`) y con las líneas plegadas ya unidas.
     *
     * @return iterable<array<string, string>>
     */
    private function vevents(string $ics): iterable
    {
        $ics = (string) preg_replace('/\r?\n[ \t]/', '', $ics);

        foreach (explode('BEGIN:VEVENT', $ics) as $i => $block) {
            if ($i === 0) {
                continue;
            }
            $props = [];
            foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
                if (preg_match('/^([A-Z-]+)[^:]*:(.*)$/', $line, $m) && !isset($props[$m[1]])) {
                    $props[$m[1]] = str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], trim($m[2]));
                }
            }
            yield $props;
        }
    }

    /** `20260925T210000`, en hora de Madrid (la del calendario). */
    private function date(?string $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if ($raw === null || !preg_match('/^\d{8}T\d{6}$/', $raw)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('Ymd\THis', $raw, $tz) ?: null;
    }
}
