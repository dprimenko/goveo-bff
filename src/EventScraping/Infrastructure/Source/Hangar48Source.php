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
 * Hangar 48 (hangar48.es), sala de conciertos y club junto al Palacio Real.
 *
 * Dos páginas, `/live-music/` y `/clubbing/`, con la programación entera en un
 * listado de JetEngine: cada evento sale con su `data-post-id` en dos bloques
 * —la fila (día, hora, nombre) y la ventana de «Más info» (foto, descripción,
 * precios con el enlace de compra)— y aquí se juntan por ese id. La API de
 * WordPress (`/wp/v2/live-music`) tiene las fichas pero sin fecha.
 *
 * - El día viene sin año («25 Sep») y la hora con un espacio («21: 00»).
 * - Las entradas se venden fuera (Ticket&Roll): el enlace de los precios.
 * - ⚠️ `/clubbing/` estaba vacío al escribir esto («No data was found»): se lee
 *   igual, suponiendo la misma maqueta que la de conciertos.
 */
final class Hangar48Source implements EventSource
{
    private const SITE    = 'https://hangar48.es';
    private const NAME    = 'Hangar 48';
    private const LAT     = 40.4113993;
    private const LNG     = -3.7140403;
    private const ADDRESS = 'Calle de Bailén, 24, 28005 Madrid';

    /** Página => [tipo, subnivel]. */
    private const PAGES = [
        '/live-music/' => ['events-small-concerts', null],
        '/clubbing/'   => ['events-nightlife', 'events-nightlife-dj-sessions'],
    ];

    /** Enlaces de la ventana que no son la compra. */
    private const SOCIAL = '/instagram|youtu|spotify|facebook|tiktok|bandcamp|soundcloud|twitter|x\.com/i';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'hangar-48';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $events = [];

        foreach (self::PAGES as $path => [$subcategory, $subtype]) {
            $html = $this->web->get(self::SITE . $path);
            if ($html === null) {
                throw new \RuntimeException(sprintf('No se pudo descargar %s de Hangar 48', $path));
            }

            $xp = Html::xpath($html);
            foreach ($this->posts($xp) as $nodes) {
                $event = $this->event($xp, $nodes, self::SITE . $path, $subcategory, $subtype);
                if ($event !== null) {
                    $events[] = $event;
                }
            }
        }

        return Shows::group($events);
    }

    /** El listado ya lo trae todo. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
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
            website: self::SITE . '/',
        );
    }

    /**
     * Los bloques de cada evento, juntos por su id.
     *
     * @return array<string, list<\DOMElement>>
     */
    private function posts(\DOMXPath $xp): array
    {
        $posts = [];
        foreach ($xp->query('//div[' . Html::hasClass('jet-listing-grid__item') . '][@data-post-id]') as $node) {
            if ($node instanceof \DOMElement) {
                $posts[$node->getAttribute('data-post-id')][] = $node;
            }
        }

        return $posts;
    }

    /** @param list<\DOMElement> $nodes */
    private function event(\DOMXPath $xp, array $nodes, string $page, string $subcategory, ?string $subtype): ?ScrapedEvent
    {
        $date = $time = $title = $image = $tickets = $description = null;

        foreach ($nodes as $n) {
            $date ??= Html::text($xp, './/i[contains(@class, "lnr-calendar")]/following-sibling::div', $n);
            $time ??= Html::text($xp, './/i[contains(@class, "lnr-clock")]/following-sibling::div', $n);
            $title ??= Html::clean(Html::text($xp, './/h2', $n), 200);
            // Lazy load: la foto de verdad está en `data-src`.
            $image ??= Html::attr($xp, './/img[@data-src]', 'data-src', $n)
                ?? Html::attr($xp, './/img[not(starts-with(@src, "data:"))]', 'src', $n);
            // La presentación de la banda (la primera, si tocan dos).
            $description ??= Html::clean(Html::text($xp, './/div[' . Html::hasClass('p-repeter') . ']', $n));

            foreach ($xp->query('.//a[@href]', $n) as $a) {
                $href = $a instanceof \DOMElement ? $a->getAttribute('href') : '';
                if ($tickets === null && preg_match('#^https?://#', $href) && !str_contains($href, 'hangar48.es') && !preg_match(self::SOCIAL, $href)) {
                    $tickets = $href;
                }
            }
        }

        $start = $this->start($date, $time);
        if ($title === null || $start === null) {
            return null;
        }

        return new ScrapedEvent(
            source: $this->name(),
            externalId: $this->slug($title),
            title: $title,
            start: $start,
            end: null,
            city: $this->city(),
            venueName: self::NAME,
            latitude: self::LAT,
            longitude: self::LNG,
            link: $tickets ?? $page,
            linkAction: $tickets !== null ? 'buy' : 'info',
            description: $description,
            imageUrl: Html::absolute($image, self::SITE),
            venueAddress: self::ADDRESS,
            subcategory: $subcategory,
            subtype: $subtype,
        );
    }

    /** «25 Sep» y «21: 00»; el año lo deduce `SpanishDate`. */
    private function start(?string $date, ?string $time): ?\DateTimeImmutable
    {
        if ($date === null || !preg_match('/(\d{1,2})\s+(\p{L}+)/u', $date, $m) || ($month = SpanishDate::month($m[2])) === null) {
            return null;
        }

        return SpanishDate::build((int) $m[1], $month, $time === null ? null : str_replace(' ', '', $time));
    }

    private function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return mb_substr(trim(preg_replace('/[^a-z0-9]+/', '-', $text) ?? '', '-'), 0, 200);
    }
}
