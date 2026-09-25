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
 * Fabrik (fabrikmadrid.com), la macrodiscoteca de electrónica de Grupo Kapital.
 *
 * La web es una aplicación de JavaScript sin nada en el HTML, pero pide su
 * agenda a una API propia y pública (`/api/fabrik/events/index.php`) que ya da
 * título, fecha, hora, cartel, enlace de entradas y texto: se lee eso, no la
 * maqueta. Las fiestas de Code, Loop, 150… de Grupo Kapital salen aquí, porque
 * se hacen en Fabrik; la web del grupo (grupo-kapital.com) no tiene agenda.
 *
 * **Está en Humanes de Madrid, no en Madrid**: el evento y el negocio van con su
 * municipio de verdad (`MUNICIPALITY`), pero la fuente es de la agenda de
 * Madrid —`city()`—, que es con lo que la lanza el cron (`--city=Madrid`): se
 * va desde Madrid y la gente de Madrid es la que la busca.
 */
final class FabrikSource implements EventSource
{
    private const SITE = 'https://www.fabrikmadrid.com';
    private const API  = self::SITE . '/api/fabrik/events/index.php?published=1&upcoming=1&limit=50';

    private const LAT     = 40.2655015;
    private const LNG     = -3.8405108;
    private const ADDRESS = 'Av. de la Industria, 82, 28970 Humanes de Madrid';

    private const MUNICIPALITY = 'Humanes de Madrid';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'fabrik';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $raw  = $this->web->get(self::API);
        $data = $raw !== null ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_array($data['data'] ?? null)) {
            throw new \RuntimeException('No se pudo leer la agenda de Fabrik');
        }

        $tz     = new \DateTimeZone('Europe/Madrid');
        $events = [];

        foreach ($data['data'] as $e) {
            $title = Html::clean($e['title'] ?? null, 200);
            $slug  = is_string($e['slug'] ?? null) ? $e['slug'] : null;
            $start = $this->start($e['date'] ?? null, $e['time'] ?? null, $tz);
            if ($title === null || $slug === null || $start === null || ($e['is_published'] ?? true) === false) {
                continue;
            }

            $detail  = self::SITE . '/eventos/' . $slug;
            $tickets = $this->url($e['ticket_link'] ?? null);
            $image   = $this->url($e['image'] ?? null) ?? $this->url($e['cover_photo_url'] ?? null);

            $events[] = new ScrapedEvent(
                source: $this->name(),
                // El slug lleva la fecha al final («…-2026-10-24»): sin ella, la
                // misma fiesta repetida es el mismo evento y `Shows` la junta.
                externalId: (string) preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $slug),
                title: $title,
                start: $start,
                end: null,
                city: self::MUNICIPALITY,
                venueName: 'Fabrik',
                latitude: self::LAT,
                longitude: self::LNG,
                link: $tickets ?? $detail,
                linkAction: $tickets !== null ? 'buy' : 'info',
                description: Html::clean($e['description'] ?? null),
                imageUrl: $image,
                detailUrl: $detail,
                venueAddress: self::ADDRESS,
                subcategory: 'events-nightlife',
                subtype: $this->subtype($title . ' ' . ($e['description'] ?? '')),
            );
        }

        return Shows::group($events);
    }

    /** La API ya lo trae todo. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: 'Fabrik',
            city: self::MUNICIPALITY,
            categorySlug: 'nightlife',
            latitude: self::LAT,
            longitude: self::LNG,
            address: self::ADDRESS,
            website: self::SITE . '/',
        );
    }

    /**
     * Electrónica casi todo; las de disfraz o de fecha señalada (Halloween,
     * Nochevieja…) y las de marca temática (Bresh, UniversiParty), temáticas.
     */
    private function subtype(string $text): string
    {
        return preg_match('/\b(halloween|navidad|nochevieja|carnaval|bresh|universiparty)\b/iu', $text)
            ? 'events-nightlife-theme-parties'
            : 'events-nightlife-electronic';
    }

    /** `2026-10-24` y `22:00h` (a veces sin la «h»), en hora de Madrid. */
    private function start(mixed $date, mixed $time, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!is_string($date) || ($day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz)) === false) {
            return null;
        }
        if (is_string($time) && preg_match('/(\d{1,2})[:.](\d{2})/', $time, $m)) {
            return $day->setTime((int) $m[1], (int) $m[2]);
        }

        return $day;
    }

    /**
     * Los enlaces vienen con las entidades escapadas varias veces
     * (`&amp;amp;amp;`) y con `--` cuando no hay: se limpian o se descartan.
     */
    private function url(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        for ($i = 0; $i < 5 && str_contains($raw, '&amp;'); ++$i) {
            $raw = html_entity_decode($raw, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        }
        $raw = trim($raw);

        return preg_match('#^https?://#', $raw) ? $raw : null;
    }
}
