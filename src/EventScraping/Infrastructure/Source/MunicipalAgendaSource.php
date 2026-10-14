<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Application\KidsKind;
use App\EventScraping\Application\Shows;
use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Base de las agendas de los ayuntamientos de la región que llegaron en el Excel
 * de planes para niños (14-10-2026): Alpedrete, Villalbilla, Moralzarzal,
 * Boadilla…
 *
 * Una agenda municipal lo mezcla todo —plenos, donaciones de sangre, talleres
 * de mayores, la feria taurina—, así que **sólo entra lo que es para niños**
 * (`KidsKind`). Una fuente cuyo listado ya es sólo infantil (Boadilla) lo dice
 * con `kidsOnly()` en `false` y todo va a Niños.
 *
 * - **Ciudad: el municipio**, no Madrid. La fuente sigue siendo de la agenda de
 *   Madrid (`city()`, con lo que la lanza el cron), como Alcalá o Fabrik.
 * - **Sin dar de alta salas**: son bibliotecas, casas de juventud y plazas. Si
 *   el sitio ya es un negocio de Goveo en ese municipio se le asigna (lo busca
 *   el importador por el nombre); si no, a la Agenda del municipio.
 * - **El sitio**: los conocidos con sus coordenadas (`places()`, sacadas de la
 *   geocodificación de Google); el resto, en el centro del pueblo (`center()`),
 *   con la dirección que diga la fuente. Mejor eso que perder el evento.
 * - Lo que pasa por internet (charlas «Online») no es un plan al que ir.
 */
abstract class MunicipalAgendaSource implements EventSource
{
    public function __construct(protected readonly WebPage $web) {}

    public function city(): string
    {
        return 'Madrid';
    }

    /** «Alpedrete»: la ciudad de los eventos. */
    abstract protected function municipality(): string;

    /** @return array{0: float, 1: float, 2: string} el ayuntamiento: lat, lng, dirección */
    abstract protected function center(): array;

    /**
     * Los eventos tal cual vienen de la web, uno por pase:
     * `id`, `title`, `start`, `end` (o nulo), `place` (nombre del sitio),
     * `address`, `lat`/`lng` (si la fuente los da), `description`, `image`,
     * `url` y `tags` (lo que la web dice del evento: su categoría, para
     * reconocer lo infantil).
     *
     * @return iterable<array<string, mixed>>
     */
    abstract protected function raw(): iterable;

    /**
     * Sitios conocidos por su nombre normalizado (`slug`): [nombre, dirección,
     * lat, lng].
     *
     * @return array<string, array{0: string, 1: string, 2: float, 3: float}>
     */
    protected function places(): array
    {
        return [];
    }

    /** Si hay que quedarse sólo con lo de niños. */
    protected function kidsOnly(): bool
    {
        return true;
    }

    public function fetch(): iterable
    {
        $performances = [];
        foreach ($this->raw() as $e) {
            $title = Html::clean($e['title'] ?? null, 200);
            $start = $e['start'] ?? null;
            $url   = $e['url'] ?? null;
            if ($title === null || !$start instanceof \DateTimeImmutable || !is_string($url)) {
                continue;
            }

            $place = trim(html_entity_decode((string) ($e['place'] ?? ''), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
            if (preg_match('/^(online|on-line|en l[ií]nea)$/iu', $place)) {
                continue;
            }

            $description = Html::clean(is_string($e['description'] ?? null) ? $e['description'] : null);
            $tags        = is_string($e['tags'] ?? null) ? $e['tags'] : '';
            // Primero el título y la categoría: la descripción de una charla
            // para padres habla de niños sin serlo.
            $kind = KidsKind::of($title . ' ' . $tags)
                ?? ($tags === '' ? KidsKind::of((string) $description) : null);
            if ($kind === null) {
                if ($this->kidsOnly()) {
                    continue;
                }
                $kind = ['events-kids', 'events-kids-family-plans'];
            }

            [$name, $address, $lat, $lng] = $this->place($place, $e);

            $end = $e['end'] ?? null;
            $end = $end instanceof \DateTimeImmutable && $end > $start ? $end : null;
            // Sin hora de inicio, dura el día entero.
            if ($end === null && $start->format('H:i') === '00:00') {
                $end = $start->setTime(23, 59);
            }

            $performances[] = new ScrapedEvent(
                source: $this->name(),
                externalId: mb_substr((string) ($e['id'] ?? md5($url)), 0, 200),
                title: $title,
                start: $start,
                end: $end,
                city: $this->municipality(),
                venueName: $name,
                latitude: $lat,
                longitude: $lng,
                link: $url,
                linkAction: 'info',
                description: $description,
                imageUrl: is_string($e['image'] ?? null) && $e['image'] !== '' ? $e['image'] : null,
                detailUrl: $url,
                venueAddress: $address,
                subcategory: $kind[0],
                subtype: $kind[1],
            );
        }

        return Shows::group($performances);
    }

    /** Sin cartel en los datos, el de la ficha para compartir. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->imageUrl !== null || $event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        return $event->withDetails(Html::attr(Html::xpath($html), '//meta[@property="og:image"]', 'content'), null);
    }

    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        return null;
    }

    /**
     * Nombre, dirección y coordenadas del sitio.
     *
     * @param array<string, mixed> $e
     *
     * @return array{0: string, 1: string, 2: float, 3: float}
     */
    private function place(string $place, array $e): array
    {
        if ($place !== '' && ($known = $this->places()[$this->slug($place)] ?? null) !== null) {
            return $known;
        }

        [$lat, $lng, $hall] = $this->center();
        $address = is_string($e['address'] ?? null) && trim($e['address']) !== ''
            ? trim($e['address'])
            : ($place !== '' ? $place . ', ' . $this->municipality() : $hall);

        return [
            // El nombre del pueblo no es un sitio: sin sala, a la Agenda.
            $this->slug($place) === $this->slug($this->municipality()) ? '' : $place,
            $address,
            is_numeric($e['lat'] ?? null) ? (float) $e['lat'] : $lat,
            is_numeric($e['lng'] ?? null) ? (float) $e['lng'] : $lng,
        ];
    }

    protected function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim(preg_replace('/[^a-z0-9]+/', '-', $text) ?? '', '-');
    }
}
