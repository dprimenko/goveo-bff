<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Application\Shows;
use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\JsonLd;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Base de las fuentes que publican sus eventos como datos estructurados
 * (`schema.org/Event`, ver `JsonLd`). Una sala nueva de este tipo es una clase
 * pequeña que dice **dónde** está su agenda, **qué sala** es y **qué tipo** de
 * evento da; leer los eventos, resolver fechas e imágenes y juntar los pases de
 * un mismo espectáculo lo hace esto.
 *
 * Los pases de un mismo espectáculo —misma URL, mismo sitio— salen como un solo
 * evento con su rango (ver `Shows`).
 */
abstract class JsonLdEventSource implements EventSource
{
    public function __construct(protected readonly WebPage $web) {}

    public function city(): string
    {
        return 'Madrid';
    }

    /** Las páginas donde están los eventos. */
    abstract protected function pages(): iterable;

    /**
     * La sala de un evento: `name`, `lat`, `lng`, `address`, `website` y
     * `category` (la de negocio, para darla de alta), o `null` para descartar el
     * evento —en Gruposmedia, un teatro que no se conoce—.
     *
     * @param array<string, mixed> $node
     *
     * @return array{name: string, lat: float, lng: float, address: string, website: string, category: string}|null
     */
    abstract protected function venue(array $node): ?array;

    /**
     * Tipo de evento y subnivel (slugs), por defecto los de la sala.
     *
     * @param array<string, mixed> $node
     *
     * @return array{0: ?string, 1: ?string}
     */
    abstract protected function classify(array $node): array;

    public function fetch(): iterable
    {
        $tz          = new \DateTimeZone('Europe/Madrid');
        $performances = [];

        foreach ($this->pages() as $html) {
            foreach (JsonLd::events($html) as $node) {
                $title = Html::clean(JsonLd::text($node['name'] ?? null, 'name'), 200);
                // «Programación semanal — Pase 22:30»: el pase sobra en un
                // evento que junta todos.
                $title = $title === null ? null : trim((string) preg_replace('/\s*[—–-]\s*Pase\s+\d{1,2}:\d{2}$/u', '', $title));
                $start = $this->date($node['startDate'] ?? null, $tz);
                $url   = JsonLd::text($node['url'] ?? null) ?? JsonLd::text($node['@id'] ?? null);
                $venue = $this->venue($node);
                if ($title === null || $start === null || $url === null || $venue === null) {
                    continue;
                }

                $end = $this->date($node['endDate'] ?? null, $tz);
                // Un fin sólo con fecha (`2026-09-25`) es «ese día»: hasta la
                // noche, no hasta la medianoche anterior.
                if ($end !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $node['endDate'])) {
                    $end = $end->setTime(23, 59);
                }
                if ($end !== null && $end <= $start) {
                    $end = null;
                }
                // Sin hora de inicio, dura el día entero.
                if ($start->format('H:i') === '00:00' && $end === null) {
                    $end = $start->setTime(23, 59);
                }

                $page    = strtok($url, '#');
                $tickets = JsonLd::text($node['offers'] ?? null);
                [$subcategory, $subtype] = $this->classify($node);

                $performances[] = new ScrapedEvent(
                    source: $this->name(),
                    // El espectáculo, no el pase: todos sus pases comparten id y
                    // `Shows` los junta en uno.
                    externalId: $this->showId($page, $venue['name']),
                    title: $title,
                    start: $start,
                    end: $end,
                    city: $this->city(),
                    venueName: $venue['name'],
                    latitude: $venue['lat'],
                    longitude: $venue['lng'],
                    link: $tickets !== null && preg_match('#^https?://#', $tickets) ? $tickets : $page,
                    linkAction: $tickets !== null ? 'buy' : 'info',
                    description: Html::clean(JsonLd::text($node['description'] ?? null, 'text')),
                    imageUrl: $this->absolute(JsonLd::text($node['image'] ?? null)),
                    detailUrl: $page,
                    venueAddress: $venue['address'],
                    subcategory: $subcategory,
                    subtype: $subtype,
                );
            }
        }

        return Shows::group($performances);
    }

    /** Sin imagen en los datos (IFEMA), la de la ficha para compartir. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->imageUrl !== null || $event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        $xp = Html::xpath($html);

        return $event->withDetails(
            $this->absolute(Html::attr($xp, '//meta[@property="og:image"]', 'content')),
            $event->description ?? Html::clean(Html::attr($xp, '//meta[@property="og:description"]', 'content')),
        );
    }

    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        $venue = $this->venueByName($event->venueName);
        if ($venue === null) {
            return null;
        }

        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue-' . $this->slug($venue['name']),
            name: $venue['name'],
            city: $this->city(),
            categorySlug: $venue['category'],
            latitude: $venue['lat'],
            longitude: $venue['lng'],
            address: $venue['address'],
            website: $venue['website'],
        );
    }

    /**
     * La configuración de una sala por su nombre, para `venueFor`. Por defecto,
     * la única que tiene la fuente.
     *
     * @return array{name: string, lat: float, lng: float, address: string, website: string, category: string}|null
     */
    protected function venueByName(string $name): ?array
    {
        return $this->venue([]);
    }

    /**
     * La página de listado y cada ficha que enlaza y casa con `$detailPattern`
     * (una expresión regular sobre la URL). Para las webs que ponen los datos
     * estructurados en la ficha de cada espectáculo y no en el listado.
     *
     * @return iterable<string>
     */
    protected function crawl(string $listing, string $detailPattern, int $max = 150): iterable
    {
        $html = $this->web->get($listing);
        if ($html === null) {
            throw new \RuntimeException(sprintf('No se pudo descargar %s', $listing));
        }
        yield $html;

        $links = [];
        foreach (Html::xpath($html)->query('//a[@href]') as $a) {
            $href = $a instanceof \DOMElement ? Html::absolute($a->getAttribute('href'), $listing) : null;
            if ($href !== null && preg_match($detailPattern, $href) && !isset($links[$href])) {
                $links[$href] = true;
            }
        }

        foreach (array_slice(array_keys($links), 0, $max) as $link) {
            if (($page = $this->web->get($link)) !== null) {
                yield $page;
            }
        }
    }

    protected function showId(string $page, string $venue): string
    {
        $path = trim((string) parse_url($page, \PHP_URL_PATH), '/');

        return mb_substr($this->slug($venue) . ':' . ($path === '' ? md5($page) : str_replace('/', '-', $path)), 0, 200);
    }

    protected function absolute(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $home = (string) parse_url($this->homeUrl(), \PHP_URL_SCHEME) . '://' . (string) parse_url($this->homeUrl(), \PHP_URL_HOST);

        return Html::absolute($url, $home);
    }

    abstract protected function homeUrl(): string;

    protected function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim(preg_replace('/[^a-z0-9]+/', '-', $text) ?? '', '-');
    }

    private function date(mixed $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            // Con zona (`+02:00`) la respeta; sin ella, es hora de Madrid.
            return (new \DateTimeImmutable(trim($raw), $tz))->setTimezone($tz);
        } catch (\Exception) {
            return null;
        }
    }
}
