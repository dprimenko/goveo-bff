<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Datos abiertos del Ayuntamiento: «Actividades culturales y de ocio municipal
 * en los próximos 100 días». Reutilizable citando la fuente.
 *
 * Dos trampas:
 *
 * - **El JSON trae caracteres de control sin escapar** dentro de los textos, y
 *   `json_decode` lo rechaza entero. Se limpian antes.
 * - **El `og:image` de madrid.es es siempre el escudo del Ayuntamiento**, no la
 *   foto del evento. La foto está en `div.image-content`, y hay fichas que no
 *   tienen ninguna: ésas se descartan.
 */
final class MadridOpenDataSource implements EventSource
{
    private const FEED = 'https://datos.madrid.es/egob/catalogo/206974-0-agenda-eventos-culturales-100.json';

    /**
     * Tipos de la agenda municipal que no son un plan: formación y charlas.
     * La agenda los mezcla con los conciertos, y sin quitarlos la cola se llena
     * de cursos anuales y clubes de lectura antes que de nada que apetezca ver.
     */
    private const EXCLUDED_TYPES = [
        'CursosTalleres', 'ConferenciasColoquios', 'ClubesLectura', 'Idiomas',
        'CapacitacionDigital', 'EnLinea',
    ];

    private const DAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

    /**
     * Cuántos eventos tiene cada sala en la agenda entera. Una sala con menos de
     * `MIN_VENUE_EVENTS` no merece ficha propia —una biblioteca con un acto al
     * trimestre—: sus eventos van a la Agenda.
     */
    private const MIN_VENUE_EVENTS = 5;

    /** @var array<string, int> */
    private array $venueCounts = [];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'madrid-datos';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $raw = $this->web->get(self::FEED, 20 * 1024 * 1024);
        if ($raw === null) {
            throw new \RuntimeException('No se pudo descargar la agenda de datos.madrid.es');
        }

        $data = json_decode((string) preg_replace('/[\x00-\x1F]/', ' ', $raw), true);
        if (!is_array($data) || !is_array($data['@graph'] ?? null)) {
            throw new \RuntimeException('La agenda de datos.madrid.es no tiene el formato esperado');
        }

        $tz = new \DateTimeZone('Europe/Madrid');

        // Antes de nada, para que `venueFor` sepa el total de cada sala y no
        // sólo lo contado hasta el evento por el que se pregunta.
        $this->venueCounts = [];
        foreach ($data['@graph'] as $e) {
            $venue = trim((string) ($e['event-location'] ?? ''));
            if ($venue !== '') {
                $this->venueCounts[$venue] = ($this->venueCounts[$venue] ?? 0) + 1;
            }
        }

        foreach ($data['@graph'] as $e) {
            $id    = (string) ($e['id'] ?? '');
            $title = trim((string) ($e['title'] ?? ''));
            $start = $this->date($e['dtstart'] ?? null, $tz);
            if ($id === '' || $title === '' || $start === null) {
                continue;
            }

            $type = substr(strrchr('/' . (string) ($e['@type'] ?? ''), '/'), 1);
            if (in_array($type, self::EXCLUDED_TYPES, true) || preg_match('/^(curso|taller)\b/iu', $title)) {
                continue;
            }

            $end  = $this->date($e['dtend'] ?? null, $tz);
            $time = trim((string) ($e['time'] ?? ''));

            // La hora viene aparte («19:00») y las fechas a medianoche. Sin hora,
            // el evento dura todo su último día: con las tres horas por defecto
            // desaparecería de madrugada.
            if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
                $start = $start->setTime((int) $m[1], (int) $m[2]);
                $end   = $end !== null && $end->format('Y-m-d') !== $start->format('Y-m-d')
                    ? $end->setTime(23, 59)
                    : null;
            } else {
                $end = ($end ?? $start)->setTime(23, 59);
            }

            $days     = array_filter(explode(',', (string) ($e['recurrence']['days'] ?? '')));
            $weekdays = array_values(array_filter(array_map(fn ($d) => self::DAYS[trim($d)] ?? null, $days)));

            $lat = $e['location']['latitude'] ?? null;
            $lng = $e['location']['longitude'] ?? null;

            yield new ScrapedEvent(
                source: $this->name(),
                externalId: $id,
                title: $title,
                start: $start,
                end: $end,
                city: $this->city(),
                venueName: trim((string) ($e['event-location'] ?? '')),
                latitude: is_numeric($lat) ? (float) $lat : null,
                longitude: is_numeric($lng) ? (float) $lng : null,
                link: $this->https($e['link'] ?? null),
                linkAction: 'info',
                description: Html::clean($e['description'] ?? null),
                detailUrl: $this->https($e['link'] ?? null),
                weekdays: $weekdays ?: null,
                venueAddress: $this->address($e['address']['area'] ?? null),
            );
        }
    }

    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        $xp  = Html::xpath($html);
        $src = Html::attr($xp, '//div[' . Html::hasClass('image-content') . ']//img', 'src');

        return $event->withDetails(Html::absolute($src, 'https://www.madrid.es'), null);
    }

    /**
     * Las salas municipales con programación de verdad salen como negocio de
     * «Cultura»: nombre, dirección y coordenadas, sin avatar ni escaparate —el
     * fichero no los trae y la ficha de la entidad da 404—. Se completan en el
     * panel.
     */
    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        if ($event->venueName === '' || ($this->venueCounts[$event->venueName] ?? 0) < self::MIN_VENUE_EVENTS) {
            return null;
        }

        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'sala-' . $this->slug($event->venueName),
            name: $event->venueName,
            city: $this->city(),
            categorySlug: 'culture-business',
            latitude: $event->latitude,
            longitude: $event->longitude,
            address: $event->venueAddress,
        );
    }

    /** «CALLE CONDE DUQUE 9», «28015» → «Calle Conde Duque 9, 28015 Madrid». */
    private function address(mixed $area): ?string
    {
        if (!is_array($area) || trim((string) ($area['street-address'] ?? '')) === '') {
            return null;
        }

        $street = mb_convert_case(mb_strtolower(trim((string) $area['street-address'])), \MB_CASE_TITLE);
        // Las partículas, en minúscula: «Calle De La Princesa» no lo escribe nadie.
        $street = preg_replace_callback('/(?<=\s)(De|Del|La|Las|Los|El|Y)(?=\s)/u', fn ($m) => mb_strtolower($m[1]), $street) ?? $street;
        $postal = trim((string) ($area['postal-code'] ?? ''));

        return trim(sprintf('%s, %s %s', $street, $postal, $this->city()), ' ,');
    }

    private function slug(string $name): string
    {
        $name = strtr(mb_strtolower($name), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim(preg_replace('/[^a-z0-9]+/', '-', $name) ?? '', '-');
    }

    private function date(mixed $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!is_string($raw) || !preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1], $tz) ?: null;
    }

    /** Los enlaces vienen en `http://`; madrid.es sirve lo mismo en `https`. */
    private function https(mixed $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }

        return preg_replace('#^http://#i', 'https://', $url);
    }
}
