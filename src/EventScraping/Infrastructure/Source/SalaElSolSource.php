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
 * Sala El Sol (salaelsol.com), la de la calle Jardines: conciertos de tarde y
 * clubbing de madrugada. Como Siroco, se lee el calendario iCal de su plugin de
 * agenda (Events Manager, `/events.ics`): toda la programación futura con fecha
 * y hora, cartel (`ATTACH`, en AVIF), ficha y el tipo que le pone la sala
 * (`Conciertos` o `Clubbing`).
 *
 * Las residencias de club se repiten con el mismo nombre —«Elements Cave» cada
 * miércoles, «Ochoymedio Club» los sábados—: el id es el título, no el `UID` de
 * cada noche, para que `Shows` las junte en un evento. Las ediciones con
 * invitado («Ochoymedio Club - Abril Zamora») llevan otro título y salen aparte.
 *
 * Las entradas (DICE, Vivaticket…) no vienen en el calendario: salen del botón
 * «Tickets» de la ficha, y sólo de lo que ya pasó el filtro de fechas.
 */
final class SalaElSolSource implements EventSource
{
    private const BASE    = 'https://salaelsol.com';
    private const NAME    = 'Sala El Sol';
    private const LAT     = 40.4190502;
    private const LNG     = -3.7016593;
    private const ADDRESS = 'Calle de los Jardines, 3, 28013 Madrid';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'sala-el-sol';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $ics = $this->web->get(self::BASE . '/events.ics');
        if ($ics === null || !str_contains($ics, 'BEGIN:VCALENDAR')) {
            throw new \RuntimeException('No se pudo leer el calendario de Sala El Sol');
        }

        $tz     = new \DateTimeZone('Europe/Madrid');
        $events = [];

        foreach ($this->vevents($ics) as $e) {
            $title = Html::clean($e['SUMMARY'] ?? null, 200);
            $start = $this->date($e['DTSTART'] ?? null, $tz);
            $url   = $e['URL'] ?? null;
            if ($title === null || $start === null || $url === null) {
                continue;
            }

            // El club empieza a las 23:59 del día que anuncian y acaba a las 6
            // del siguiente: se deja así, como en las demás salas.
            $end = $this->date($e['DTEND'] ?? null, $tz);
            if ($end !== null && $end <= $start) {
                $end = null;
            }

            [$subcategory, $subtype] = $this->classify(mb_strtolower($e['CATEGORIES'] ?? ''), $title);

            $events[] = new ScrapedEvent(
                source: $this->name(),
                externalId: $this->slug($title),
                title: $title,
                start: $start,
                end: $end,
                city: $this->city(),
                venueName: self::NAME,
                latitude: self::LAT,
                longitude: self::LNG,
                link: $url,
                linkAction: 'info',
                description: Html::clean($e['DESCRIPTION'] ?? null),
                imageUrl: $e['ATTACH'] ?? null,
                detailUrl: $url,
                venueAddress: self::ADDRESS,
                subcategory: $subcategory,
                subtype: $subtype,
            );
        }

        return Shows::group($events);
    }

    /** El botón «Tickets» de la ficha, si lo tiene; si no, la ficha. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        $tickets = Html::attr(Html::xpath($html), '//a[' . Html::hasClass('Tickets') . ']', 'href');

        return $tickets !== null && preg_match('#^https?://#', $tickets)
            ? $event->withDetails(null, null, $tickets, 'buy')
            : $event;
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
            website: self::BASE . '/',
        );
    }

    /**
     * El tipo lo pone la sala. Del clubbing, las fiestas con tema —la
     * «after party» de un disco, un tributo— se dicen en el título; el resto
     * son sesiones de DJ, que es lo que la sala anuncia.
     *
     * @return array{0: string, 1: ?string}
     */
    private function classify(string $category, string $title): array
    {
        if (!str_contains($category, 'clubbing')) {
            return ['events-small-concerts', null];
        }

        return preg_match('/after\s*party|tributo|tem[aá]tic|revival|remember/iu', $title)
            ? ['events-nightlife', 'events-nightlife-theme-parties']
            : ['events-nightlife', 'events-nightlife-dj-sessions'];
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

    /** `20260925T203000`, en hora de Madrid (la del calendario). */
    private function date(?string $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if ($raw === null || !preg_match('/^\d{8}T\d{6}$/', $raw)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('Ymd\THis', $raw, $tz) ?: null;
    }

    private function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return mb_substr(trim(preg_replace('/[^a-z0-9]+/', '-', $text) ?? '', '-'), 0, 200);
    }
}
