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
 * Stage Entertainment (stage.es): sus musicales de Madrid, cada uno en su
 * teatro —el Lope de Vega («El Rey León») y el Coliseum—.
 *
 * `/musicales-madrid` enlaza la ficha de cada musical, y la ficha dice en sus
 * datos estructurados (`EventSeries`) qué es y en qué teatro está, pero **no
 * cuándo**: ni fin ni funciones. Las funciones las pinta el calendario de
 * `/musicales/{slug}/entradas`, que las pide a la propia web
 * (`/ajax/products`, con el `data-id` y `data-page` del componente de compra):
 * una por función, con su hora. Es la web de Stage, no la venta —que está en
 * `entradas.stage.es` y no se lee—.
 *
 * Las funciones de un musical se juntan en un evento (`Shows`), hasta la última
 * que esté a la venta: cuando abran más fechas, la pasada alarga el fin.
 */
final class StageSource implements EventSource
{
    private const HOME    = 'https://www.stage.es';
    private const LISTING = self::HOME . '/musicales-madrid';

    /** Por el nombre del teatro en los datos estructurados de la ficha. */
    private const VENUES = [
        'teatro lope de vega' => [
            'name'    => 'Teatro Lope de Vega',
            'lat'     => 40.4218277,
            'lng'     => -3.7087124,
            'address' => 'Gran Vía, 57, 28013 Madrid',
            'website' => self::HOME . '/teatro-lope-de-vega',
        ],
        'teatro coliseum' => [
            'name'    => 'Teatro Coliseum',
            'lat'     => 40.4232844,
            'lng'     => -3.7103397,
            'address' => 'Gran Vía, 78, 28013 Madrid',
            'website' => self::HOME . '/teatro-ocaso-coliseum',
        ],
    ];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'stage';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::LISTING);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la cartelera de Stage Entertainment');
        }

        // Sólo la ficha del musical (`/musicales/el-rey-leon`): las demás rutas
        // de la sección son promociones, el bono cultural, el «próximo musical»…
        $slugs = [];
        foreach (Html::xpath($html)->query('//a[@href]') as $a) {
            if ($a instanceof \DOMElement && preg_match('#^/musicales/([a-z0-9-]+)/?$#', $a->getAttribute('href'), $m)) {
                $slugs[$m[1]] = true;
            }
        }

        $performances = [];
        foreach (array_keys($slugs) as $slug) {
            foreach ($this->show($slug) as $performance) {
                $performances[] = $performance;
            }
        }

        return Shows::group($performances);
    }

    /** La ficha y el calendario ya lo traen todo. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        $venue = self::VENUES[mb_strtolower($event->venueName)] ?? null;
        if ($venue === null) {
            return null;
        }

        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue-' . basename($venue['website']),
            name: $venue['name'],
            city: $this->city(),
            categorySlug: 'culture-business',
            latitude: $venue['lat'],
            longitude: $venue['lng'],
            address: $venue['address'],
            website: $venue['website'],
        );
    }

    /**
     * Las funciones de un musical. Sin serie en los datos estructurados (una
     * página que no es un espectáculo), sin teatro conocido, sin foto o sin
     * funciones a la venta, nada.
     *
     * @return list<ScrapedEvent>
     */
    private function show(string $slug): array
    {
        $page = self::HOME . '/musicales/' . $slug;
        $html = $this->web->get($page, 2 * 1024 * 1024);
        if ($html === null || ($series = $this->series($html)) === null) {
            return [];
        }

        $venue = self::VENUES[mb_strtolower(trim((string) ($series['location']['name'] ?? '')))] ?? null;
        $title = Html::clean(is_string($series['name'] ?? null) ? $series['name'] : null, 200);
        $image = $this->hero(Html::xpath($html));
        if ($venue === null || $title === null || $image === null) {
            return [];
        }

        $tickets  = $page . '/entradas';
        $calendar = $this->web->get($tickets);
        if ($calendar === null) {
            return [];
        }
        $cx       = Html::xpath($calendar);
        $checkout = '//div[' . Html::hasClass('js-checkout') . ']';
        $id       = Html::attr($cx, $checkout, 'data-id');
        $node     = Html::attr($cx, $checkout, 'data-page');
        $callback = Html::attr($cx, $checkout, 'data-callback') ?? '/ajax/products';
        if ($id === null || $node === null) {
            return [];
        }

        // El calendario pide dos años por delante; basta con uno.
        $tz    = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $tz);
        $json  = $this->web->get(Html::absolute($callback, self::HOME) . '?' . http_build_query([
            'id'        => $id,
            'page'      => $node,
            'startDate' => $today->format('Y-m-d'),
            'endDate'   => $today->modify('+1 year')->format('Y-m-d'),
            'init'      => 1,
        ]));
        $data = $json !== null ? json_decode($json, true) : null;
        if (!is_array($data) || !is_array($data['events'] ?? null)) {
            return [];
        }

        $description = Html::clean(is_string($series['description'] ?? null) ? $series['description'] : null, 400);
        $out         = [];
        foreach ($data['events'] as $performance) {
            $iso = $performance['date']['iso8601'] ?? null;
            if (!is_string($iso)) {
                continue;
            }
            try {
                $start = (new \DateTimeImmutable($iso))->setTimezone($tz);
            } catch (\Exception) {
                continue;
            }

            $out[] = new ScrapedEvent(
                source: $this->name(),
                // El musical, no la función: `Shows` junta sus pases.
                externalId: $slug,
                title: $title,
                start: $start,
                end: null,
                city: $this->city(),
                venueName: $venue['name'],
                latitude: $venue['lat'],
                longitude: $venue['lng'],
                // La página de entradas de la web, no la de la venta: la de cada
                // función caduca con ella.
                link: $tickets,
                linkAction: 'buy',
                description: $description,
                imageUrl: $image,
                detailUrl: $page,
                venueAddress: $venue['address'],
                subcategory: preg_match('/concierto/iu', $title) ? 'events-small-concerts' : 'events-stage',
                subtype: preg_match('/concierto/iu', $title) ? null : 'events-stage-musicals',
            );
        }

        return $out;
    }

    /**
     * El nodo `EventSeries` de la ficha. `JsonLd` sólo recoge eventos sueltos, y
     * aquí el musical es una serie.
     *
     * @return array<string, mixed>|null
     */
    private function series(string $html): ?array
    {
        if (!preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $m)) {
            return null;
        }
        foreach ($m[1] as $raw) {
            $data = json_decode(trim($raw), true);
            foreach (is_array($data) ? ($data['@graph'] ?? [$data]) : [] as $node) {
                if (is_array($node) && in_array('EventSeries', (array) ($node['@type'] ?? []), true)) {
                    return $node;
                }
            }
        }

        return null;
    }

    /**
     * La foto de cabecera: la primera imagen de su banco de medios
     * (`mediaportal.stage-entertainment.com`), que se sirve a cualquier ancho
     * por `srcset`. Se toma la primera de al menos 1.200 px —el cartel se
     * encaja luego en vertical y no se amplía—. La web no tiene `og:image` en
     * las fichas.
     */
    private function hero(\DOMXPath $xp): ?string
    {
        $srcset = Html::attr($xp, '//img[contains(@data-srcset, "mediaportal.stage-entertainment.com")]', 'data-srcset');
        $best   = null;
        foreach (explode(',', html_entity_decode((string) $srcset)) as $candidate) {
            if (preg_match('/^\s*(\S+)\s+(\d+)w/', $candidate, $c)) {
                $best = $c[1];
                if ((int) $c[2] >= 1200) {
                    break;
                }
            }
        }

        return $best;
    }
}
