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
 * Gran Teatro Pavón (granteatropavon.com): la sala y su Ambigú.
 *
 * La portada pinta el calendario («Cartelera por espacios») con un plugin que
 * deja **las funciones escritas en la propia página**, en la variable
 * `cloudariCalendarVenuesData`: una por pase, con su hora, la sala, el cartel,
 * la sinopsis y el enlace de compra (Onebox, que sólo se enlaza). Es lo que la
 * web ya publica; no hace falta abrir fichas ni preguntar a la venta.
 *
 * Trae unas semanas por delante —las funciones a la venta—, que es lo que mira
 * el filtro. El Ambigú es una sala del mismo edificio: sus eventos van al
 * mismo negocio.
 */
final class TeatroPavonSource implements EventSource
{
    private const HOME = 'https://www.granteatropavon.com/';

    private const VENUE = [
        'name'    => 'Gran Teatro Pavón',
        'lat'     => 40.410031,
        'lng'     => -3.7061552,
        'address' => 'Calle de Embajadores, 9, 28012 Madrid',
    ];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'teatro-pavon';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::HOME);
        if ($html === null || !preg_match('/var\s+cloudariCalendarVenuesData\s*=\s*(\{.*?\});\s*$/m', $html, $m)) {
            throw new \RuntimeException('No se pudo leer la cartelera del Gran Teatro Pavón');
        }
        $data = json_decode($m[1], true);
        if (!is_array($data['sesiones']['data'] ?? null)) {
            throw new \RuntimeException('La cartelera del Gran Teatro Pavón ha cambiado de forma');
        }

        $tz           = new \DateTimeZone('Europe/Madrid');
        $performances = [];
        foreach ($data['sesiones']['data'] as $session) {
            $event = $session['event'] ?? null;
            if (!is_array($event) || !isset($event['id']) || !is_string($session['date']['start'] ?? null)) {
                continue;
            }

            $texts       = $event['texts'] ?? [];
            $title       = Html::clean($texts['title']['es-ES'] ?? $event['name'] ?? null, 200);
            $subtitle    = Html::clean($texts['subtitle']['es-ES'] ?? null, 200);
            // La corta viene a veces vacía.
            $description = Html::clean($texts['description_short']['es-ES'] ?? null, 400)
                ?? Html::clean($texts['description_long']['es-ES'] ?? null, 400);
            // `main` es el cartel; `landscape`, la tira apaisada del calendario.
            $image = $event['images']['main']['es-ES'] ?? $event['images']['landscape'][0]['es-ES'] ?? null;
            if ($title === null || !is_string($image)) {
                continue;
            }

            try {
                $start = (new \DateTimeImmutable($session['date']['start']))->setTimezone($tz);
                $end   = is_string($session['date']['end'] ?? null) ? (new \DateTimeImmutable($session['date']['end']))->setTimezone($tz) : null;
            } catch (\Exception) {
                continue;
            }

            $tickets                 = is_string($session['url'] ?? null) ? $session['url'] : null;
            [$subcategory, $subtype] = $this->classify(mb_strtolower($title . ' ' . $subtitle . ' ' . $description));

            $performances[] = new ScrapedEvent(
                source: $this->name(),
                // El espectáculo, no el pase: `Shows` los junta.
                externalId: (string) $event['id'],
                title: $title,
                start: $start,
                end: $end !== null && $end > $start ? $end : null,
                city: $this->city(),
                venueName: self::VENUE['name'],
                latitude: self::VENUE['lat'],
                longitude: self::VENUE['lng'],
                // Sin el número de la sesión: el del espectáculo sigue valiendo
                // cuando esa función ya ha pasado.
                link: $tickets !== null ? (string) preg_replace('#(/events/\d+).*$#', '$1', $tickets) : self::HOME,
                linkAction: $tickets !== null ? 'buy' : 'info',
                description: $description,
                imageUrl: $image,
                detailUrl: self::HOME,
                venueAddress: self::VENUE['address'],
                subcategory: $subcategory,
                subtype: $subtype,
            );
        }

        return Shows::group($performances);
    }

    /** La portada ya lo trae todo. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
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
            website: self::HOME,
        );
    }

    /**
     * El plugin no trae género: sale del título, el subtítulo («Teatro para
     * bebés», «Un musical diferente») y la sinopsis.
     *
     * @return array{0: string, 1: ?string}
     */
    private function classify(string $text): array
    {
        return match (true) {
            (bool) preg_match('/\btaller\b/u', $text) => ['events-experiences', 'events-experiences-workshops'],
            str_contains($text, 'flamenco') => ['events-flamenco', 'events-flamenco-show'],
            (bool) preg_match('/\bjazz\b|concierto|sesi[oó]n vermut/u', $text) => ['events-small-concerts', null],
            (bool) preg_match('/\bmusical\b/u', $text) => ['events-stage', 'events-stage-musicals'],
            (bool) preg_match('/\bmag(ia|o)\b|ilusionis/u', $text) => ['events-stage', 'events-stage-magic'],
            (bool) preg_match('/comedia|humor|mon[oó]logo|imitador/u', $text) => ['events-stage', 'events-stage-comedy'],
            default => ['events-stage', 'events-stage-theater'],
        };
    }
}
