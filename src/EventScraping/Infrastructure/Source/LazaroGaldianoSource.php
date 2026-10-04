<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Museo Lázaro Galdiano (museolazarogaldiano.es; flg.es redirige allí).
 *
 * Su agenda se pinta con el JSON de `/api/v1/eventos.json`: sin filtros
 * devuelve **todo**, también lo de años pasados, agrupado por tipo y con
 * inicio, fin, imagen y entradas. Las horas vienen **en UTC** (un concierto
 * «a las 12 h» llega como `10:00`).
 *
 * La descripción no viene: sale de la ficha.
 */
final class LazaroGaldianoSource implements EventSource
{
    private const API = 'https://www.museolazarogaldiano.es/api/v1/eventos.json';

    /**
     * Grupo del JSON → tipo y subnivel. Conferencias y campamentos (semanas
     * de verano con matrícula) no se piden.
     */
    private const TYPES = [
        'Exposiciones_temporales' => ['events-art', 'events-art-temporary'],
        'Conciertos'              => ['events-small-concerts', null],
        'Visitas_guiadas'         => ['events-experiences', 'events-experiences-guided-tours'],
        'Talleres'                => ['events-experiences', 'events-experiences-workshops'],
        'Artes_escénicas'         => ['events-stage', null],
    ];

    private const VENUE = [
        'name'    => 'Museo Lázaro Galdiano',
        'lat'     => 40.4369187,
        'lng'     => -3.6857512,
        'address' => 'Calle de Serrano, 122, 28006 Madrid',
        'website' => 'https://www.museolazarogaldiano.es/',
    ];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'lazaro-galdiano';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $raw  = $this->web->get(self::API);
        $data = $raw !== null ? json_decode($raw, true) : null;
        if (!is_array($data['data'] ?? null)) {
            throw new \RuntimeException('No se pudo leer la agenda del Museo Lázaro Galdiano');
        }

        $tz  = new \DateTimeZone('Europe/Madrid');
        $now = new \DateTimeImmutable('now', $tz);

        foreach (self::TYPES as $group => [$subcategory, $subtype]) {
            foreach ($data['data'][$group] ?? [] as $item) {
                $title = Html::clean($item['title'] ?? null, 200);
                $url   = is_string($item['url'] ?? null) ? $item['url'] : null;
                $start = $this->date($item['fecha_inicio'] ?? null, $tz);
                $end   = $this->date($item['fecha_fin'] ?? null, $tz);
                $place = (string) ($item['lugar'] ?? '');
                // Las que el museo lleva fuera («El Museo en Granada»).
                if ($title === null || $url === null || $start === null || !preg_match('/l[aá]zaro galdiano|serrano/iu', $place)) {
                    continue;
                }
                if (($end ?? $start) < $now) {
                    continue;
                }

                $exhibition = $group === 'Exposiciones_temporales';
                // Una exposición es de días enteros: «hasta el 15 de noviembre»
                // llega como la medianoche anterior en UTC.
                if ($exhibition) {
                    $start = $start->setTime(0, 0);
                    $end   = ($end ?? $start)->setTime(23, 59);
                }

                $kind = $this->kind($title, $subcategory, $subtype, $exhibition);
                $tickets = is_string($item['url_entradas'] ?? null) && preg_match('#^https?://#', $item['url_entradas']) ? $item['url_entradas'] : null;

                yield new ScrapedEvent(
                    source: $this->name(),
                    externalId: 'nid-' . ($item['nid'] ?? md5($url)),
                    title: $title,
                    start: $start,
                    end: $end !== null && $end > $start ? $end : null,
                    city: $this->city(),
                    venueName: self::VENUE['name'],
                    latitude: self::VENUE['lat'],
                    longitude: self::VENUE['lng'],
                    link: $tickets ?? $url,
                    linkAction: $tickets !== null ? 'buy' : 'info',
                    imageUrl: is_string($item['imagen']['url'] ?? null) ? $item['imagen']['url'] : null,
                    detailUrl: $url,
                    venueAddress: self::VENUE['address'],
                    subcategory: $kind[0],
                    subtype: $kind[1],
                );
            }
        }
    }

    /** La descripción, de la ficha. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        $xp = Html::xpath($html);

        return $event->withDetails(null, Html::clean(Html::attr($xp, '//meta[@name="description"]', 'content'), 400));
    }

    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue-lazaro-galdiano',
            name: self::VENUE['name'],
            city: $this->city(),
            categorySlug: 'tourism-museums',
            latitude: self::VENUE['lat'],
            longitude: self::VENUE['lng'],
            address: self::VENUE['address'],
            website: self::VENUE['website'],
        );
    }

    /**
     * El del grupo, afinado por el título: lo de familias y niños va a Niños,
     * y una exposición de fotografía (PHotoESPAÑA) a su subnivel.
     *
     * @return array{0: string, 1: ?string}
     */
    private function kind(string $title, string $subcategory, ?string $subtype, bool $exhibition): array
    {
        $title = mb_strtolower($title);

        return match (true) {
            $exhibition && (bool) preg_match('/fotograf|photoespa/u', $title) => ['events-art', 'events-art-photography'],
            $exhibition                                                     => [$subcategory, $subtype],
            (bool) preg_match('/familia|niñ[oa]s|infantil|peques/u', $title) => str_contains($title, 'taller')
                ? ['events-kids', 'events-kids-workshops']
                : ['events-kids', 'events-kids-family-plans'],
            default => [$subcategory, $subtype],
        };
    }

    /** `2026-10-04T10:00:00`, en UTC. */
    private function date(mixed $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))->setTimezone($tz);
        } catch (\Exception) {
            return null;
        }
    }
}
