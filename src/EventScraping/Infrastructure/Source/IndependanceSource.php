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
 * Independance Club (independanceclub.com), en Atocha: una tienda Shopify donde
 * **cada evento es un producto** —se compra la entrada como se compraría una
 * camiseta—. Se lee el catálogo en JSON (`/products.json`), que Shopify publica
 * en todas sus tiendas: título, cartel, texto y etiquetas, sin maqueta.
 *
 * ⚠️ **La fecha sólo está en el título** («STORMY - MIÉRCOLES 28 OCTUBRE A LAS
 * 20H», «FIESTA … - SÁBADO 19 DICIEMBRE DE 23.30H 06H»): ni el producto ni su
 * ficha la tienen en un campo. Tampoco hay que fiarse del `handle`, que a veces
 * conserva la fecha de una versión anterior del título.
 *
 * El tipo sale de sus etiquetas: CONCIERTOS, TARDES (tardeos) y NOCHES
 * (fiestas). La misma fiesta repetida en varias fechas sale una vez, con su
 * rango (ver `Shows`).
 */
final class IndependanceSource implements EventSource
{
    private const SITE      = 'https://independanceclub.com';
    private const MAX_PAGES = 4;
    private const WEEKDAY   = '(?:LUNES|MARTES|MI[ÉE]RCOLES|JUEVES|VIERNES|S[ÁA]BADO|DOMINGO)';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'independance';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $performances = [];

        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $raw      = $this->web->get(sprintf('%s/products.json?limit=250&page=%d', self::SITE, $page));
            $products = $raw !== null ? (json_decode($raw, true)['products'] ?? null) : null;
            if (!is_array($products)) {
                if ($page === 1) {
                    throw new \RuntimeException('No se pudo leer el catálogo de Independance');
                }
                break;
            }
            if ($products === []) {
                break;
            }

            foreach ($products as $product) {
                if (($event = $this->event($product)) !== null) {
                    $performances[] = $event;
                }
            }
        }

        return Shows::group($performances);
    }

    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: 'Independance Club',
            city: $this->city(),
            categorySlug: 'nightlife',
            latitude: 40.4096706,
            longitude: -3.6929809,
            address: 'Calle de Atocha, 127, 28012 Madrid',
            website: self::SITE . '/',
        );
    }

    /** @param array<string, mixed> $product */
    private function event(array $product): ?ScrapedEvent
    {
        $full   = Html::clean($product['title'] ?? null, 300);
        $handle = is_string($product['handle'] ?? null) ? $product['handle'] : null;
        $image  = $product['images'][0]['src'] ?? null;
        // «NOMBRE - DÍA 17 [DE] OCTUBRE resto»: el nombre es lo que va antes del
        // guion que precede al día de la semana (puede haber otros guiones antes).
        if ($full === null || $handle === null || !is_string($image)
            || !preg_match('/^(.+?)\s*[-–—]\s*' . self::WEEKDAY . '\s+(\d{1,2})\s+(?:DE\s+)?([A-ZÁÉÍÓÚ]+)(.*)$/iu', $full, $m)
            || ($month = SpanishDate::month($m[3])) === null) {
            return null;
        }

        [$title, $day, $rest] = [trim($m[1]), (int) $m[2], $m[4]];

        // Horas: «A LAS 20H», «DE 18H A 23:30H», «DE 23.30H 06H». La primera es
        // el inicio y, si es un «de … a …», la segunda el final.
        preg_match_all('/(\d{1,2})(?:[:.](\d{2}))?\s*H\b/iu', $rest, $times, \PREG_SET_ORDER);
        $start = SpanishDate::build($day, $month, isset($times[0]) ? sprintf('%d:%s', $times[0][1], ($times[0][2] ?? '') ?: '00') : null);
        if ($start === null) {
            return null;
        }

        $end = null;
        if (isset($times[1]) && preg_match('/\bDE\b/iu', $rest)) {
            $end = $start->setTime((int) $times[1][1], (int) ($times[1][2] ?? 0));
            // «De 23:30 a 06:00» acaba al día siguiente.
            if ($end <= $start) {
                $end = $end->modify('+1 day');
            }
        } elseif (!isset($times[0])) {
            $end = $start->setTime(23, 59);
        }

        $tags = array_map('mb_strtoupper', is_array($product['tags'] ?? null) ? $product['tags'] : []);
        [$subcategory, $subtype] = $this->classify($title, $tags);
        $url = self::SITE . '/products/' . $handle;

        return new ScrapedEvent(
            source: $this->name(),
            // El nombre y no el producto: cada fecha de una misma fiesta es un
            // producto distinto, y `Shows` las junta por este id.
            externalId: $this->slug($title),
            title: $title,
            start: $start,
            end: $end,
            city: $this->city(),
            venueName: 'Independance Club',
            latitude: 40.4096706,
            longitude: -3.6929809,
            // La ficha del producto es donde se compra la entrada.
            link: $url,
            linkAction: 'buy',
            description: $this->description($product['body_html'] ?? null),
            imageUrl: $image,
            detailUrl: $url,
            venueAddress: 'Calle de Atocha, 127, 28012 Madrid',
            subcategory: $subcategory,
            subtype: $subtype,
        );
    }

    /**
     * @param list<string> $tags
     *
     * @return array{0: string, 1: ?string}
     */
    private function classify(string $title, array $tags): array
    {
        if (in_array('TARDES', $tags, true)) {
            return ['events-nightlife', 'events-nightlife-tardeo'];
        }
        if (in_array('NOCHES', $tags, true)) {
            // Casi todas son fiestas de una época o un artista (los 90, Madonna,
            // Halloween); las de techno son sesión de DJ.
            return preg_match('/\b(techno|club|dj)\b/iu', $title)
                ? ['events-nightlife', 'events-nightlife-dj-sessions']
                : ['events-nightlife', 'events-nightlife-theme-parties'];
        }

        return ['events-small-concerts', null];
    }

    /** El texto sin la lista de horarios y precios, que ya van en la fecha y la ficha. */
    private function description(mixed $html): ?string
    {
        if (!is_string($html)) {
            return null;
        }
        $html = preg_split('/HORARIOS?\s*:/iu', $html)[0] ?? $html;

        return Html::clean(str_ireplace(['</p>', '</div>', '<br>'], ' ', $html));
    }

    private function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return mb_substr(trim(preg_replace('/[^a-z0-9]+/', '-', $text) ?? '', '-'), 0, 150);
    }
}
