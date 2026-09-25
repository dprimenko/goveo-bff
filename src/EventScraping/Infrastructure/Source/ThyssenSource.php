<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\JsonLd;

/**
 * Museo Thyssen-Bornemisza: exposiciones temporales y actividades públicas.
 *
 * Cada ficha lleva sus datos estructurados (`schema.org/Event`), pero **sin
 * hora y sin tipo**: el tipo («Concierto», «Visita guiada», «Curso»…) sale de la
 * tarjeta del listado, y la hora y a quién va dirigido, del cuerpo de la ficha
 * (ver `remember`).
 *
 * La agenda mezcla mucho que no es un plan para cualquiera: actividades sólo
 * para Amigos del museo (viajes, visitas a otras ferias), cursos, conferencias
 * y todo lo de profesorado de EducaThyssen. Sólo entran los tipos de `KINDS`.
 */
final class ThyssenSource extends JsonLdEventSource
{
    private const SITE = 'https://www.museothyssen.org';

    /** Tipo de la tarjeta (en minúsculas) → tipo y subnivel de Goveo. */
    private const KINDS = [
        'concierto'     => ['events-small-concerts', null],
        'cine'          => ['events-experiences', 'events-experiences-cinema'],
        'taller'        => ['events-experiences', 'events-experiences-workshops'],
        'visita guiada' => ['events-experiences', 'events-experiences-guided-tours'],
        // Performances de artistas en las salas: arte, no escena.
        'performance'   => ['events-art', null],
    ];

    private const VENUE = [
        'name'     => 'Museo Thyssen-Bornemisza',
        'lat'      => 40.4160406,
        'lng'      => -3.6949254,
        'address'  => 'Paseo del Prado, 8, 28014 Madrid',
        'website'  => 'https://www.museothyssen.org/',
        'category' => 'culture-business',
    ];

    /** @var array<string, string> URL de la ficha → tipo de su tarjeta */
    private array $kinds = [];

    /** @var array<string, string> URL de la ficha → enlace de entradas de su tarjeta */
    private array $tickets = [];

    /** @var array<string, string> URL de la ficha → a quién va dirigida */
    private array $audiences = [];

    /** @var array<string, string> URL de la ficha → hora («19:30») */
    private array $hours = [];

    public function name(): string
    {
        return 'thyssen';
    }

    protected function homeUrl(): string
    {
        return self::SITE . '/';
    }

    protected function pages(): iterable
    {
        $crawls = [
            self::SITE . '/exposiciones' => '#^https://www\.museothyssen\.org/exposiciones/[a-z0-9-]+$#',
            // Los talleres para el público salen en EducaThyssen, su web de
            // educación; lo de profesores y colegios cuelga de otras rutas.
            self::SITE . '/actividades'  => '#^https://www\.(museothyssen\.org/actividades|educathyssen\.org/programas-publicos)/[a-z0-9/-]+$#',
        ];

        foreach ($crawls as $listing => $pattern) {
            foreach ($this->crawl($listing, $pattern) as $html) {
                $this->remember($html);
                yield $html;
            }
        }
    }

    /** La hora de la ficha, que los datos estructurados no traen. */
    public function fetch(): iterable
    {
        foreach (parent::fetch() as $event) {
            yield $this->atHour($event);
        }
    }

    /** Las exposiciones no llevan entradas en sus datos; la tarjeta, sí. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        $tickets = $this->tickets[$event->detailUrl ?? ''] ?? null;
        $event   = parent::enrich($event);

        return $event->linkAction === 'info' && $tickets !== null ? $event->withDetails(null, null, $tickets, 'buy') : $event;
    }

    protected function venue(array $node): ?array
    {
        $url = (string) JsonLd::text($node['url'] ?? null);
        if ($url === '') {
            return self::VENUE;
        }

        // Lo de Amigos del museo (y «Amigos Jóvenes») no está abierto a todos.
        if (str_contains($this->audiences[$url] ?? '', 'amigos') || $this->kind($url) === null) {
            return null;
        }

        return self::VENUE;
    }

    protected function classify(array $node): array
    {
        $url  = (string) JsonLd::text($node['url'] ?? null);
        $kind = $this->kind($url);
        if ($kind !== 'exposición') {
            return self::KINDS[$kind] ?? ['events-other', null];
        }

        $about = mb_strtolower(Html::clean(JsonLd::text($node['name'] ?? null, 'name') . ' ' . mb_substr((string) JsonLd::text($node['description'] ?? null, 'text'), 0, 300)) ?? '');

        return match (true) {
            str_contains($about, 'inmersiv')                                    => ['events-art', 'events-art-immersive'],
            str_contains($about, 'fotograf') || str_contains($about, 'fotógraf') => ['events-art', 'events-art-photography'],
            default                                                             => ['events-art', 'events-art-temporary'],
        };
    }

    /** `exposición`, una clave de `KINDS` o `null` si no es de las que interesan. */
    private function kind(string $url): ?string
    {
        $kind = $this->kinds[$url] ?? null;
        if ($kind !== null && str_starts_with($kind, 'exposición')) {
            return 'exposición';
        }

        return $kind !== null && isset(self::KINDS[$kind]) ? $kind : null;
    }

    /**
     * Lo que los datos estructurados no traen. Del listado, el tipo y las
     * entradas de cada tarjeta; de la ficha, a quién va dirigida y la hora.
     */
    private function remember(string $html): void
    {
        $xp = Html::xpath($html);

        foreach ($xp->query('//a[' . Html::hasClass('snippet__caption') . ']') as $card) {
            $url  = $card instanceof \DOMElement ? Html::absolute($card->getAttribute('href'), self::SITE) : null;
            $kind = Html::text($xp, './/*[' . Html::hasClass('snippet__kicker') . ']', $card);
            if ($url === null || $kind === null) {
                continue;
            }
            // Manda la tarjeta del listado, que se lee primero: las fichas traen
            // tarjetas de «relacionados» con otro rótulo.
            $this->kinds[$url] ??= mb_strtolower($kind);

            $tickets = Html::attr($xp, 'following-sibling::*//a[' . Html::hasClass('gtm-tickets') . ']', 'href', $card);
            if ($tickets !== null) {
                $this->tickets[$url] ??= $tickets;
            }
        }

        $url = Html::attr($xp, '//link[@rel="canonical"]', 'href') ?? Html::attr($xp, '//meta[@property="og:url"]', 'content');
        if ($url === null) {
            return;
        }

        $audience = [];
        // Sólo el de la propia ficha: las tarjetas de actividades relacionadas
        // llevan también sus etiquetas.
        foreach ($xp->query('//dt[normalize-space() = "Dirigido a:"]/following-sibling::dd[1]//a') as $a) {
            $audience[] = mb_strtolower(trim($a->textContent));
        }
        $this->audiences[$url] = implode(' ', $audience);

        $hour = (string) Html::text($xp, '//dt[normalize-space() = "Hora:"]/following-sibling::dd[1]');
        // Sólo una hora clara al principio («17:30 Duración: 1 h»): «21:00 y
        // 22:00» o «De 17:30 a 19:00» se dejan en el día entero antes que elegir mal.
        if (preg_match('/^(\d{1,2}[:.]\d{2})(?!\s*(?:y|a|-|–)\s*\d)/u', $hour, $m)) {
            $this->hours[$url] = $m[1];
        }
    }

    /**
     * Con la hora de la ficha, el evento empieza a esa hora. Si es de un solo
     * día se queda sin fin, que es lo que dice la ficha (sin él, el feed
     * cuenta tres horas).
     */
    private function atHour(ScrapedEvent $e): ScrapedEvent
    {
        $hour = $this->hours[$e->detailUrl ?? ''] ?? null;
        if ($hour === null || $e->start->format('H:i') !== '00:00') {
            return $e;
        }

        [$h, $m] = array_map('intval', preg_split('/[:.]/', $hour) ?: [0, 0]);
        $start   = $e->start->setTime($h, $m);
        $oneDay  = $e->end === null || $e->end->format('Y-m-d') === $e->start->format('Y-m-d');

        return new ScrapedEvent(
            source: $e->source,
            externalId: $e->externalId,
            title: $e->title,
            start: $start,
            end: $oneDay ? null : $e->end,
            city: $e->city,
            venueName: $e->venueName,
            latitude: $e->latitude,
            longitude: $e->longitude,
            link: $e->link,
            linkAction: $e->linkAction,
            description: $e->description,
            imageUrl: $e->imageUrl,
            detailUrl: $e->detailUrl,
            weekdays: $e->weekdays,
            venueAddress: $e->venueAddress,
            subcategory: $e->subcategory,
            subtype: $e->subtype,
        );
    }
}
