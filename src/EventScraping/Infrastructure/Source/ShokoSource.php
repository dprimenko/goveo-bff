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
 * Shôko Madrid, club de la calle Toledo. La web es una tienda de Shopify y cada
 * noche es un «producto» (la entrada) de la colección `eventos-shoko-madrid`,
 * así que la agenda sale del JSON que Shopify sirve de cualquier colección:
 * título con la fecha («PURE SHÔKO - Saturday, 26.9.2026») y el cartel.
 *
 * La hora no está en ese JSON, sino en un campo de la ficha («… | A partir de
 * las 23:45»). Como casi todo son fiestas fijas de cada semana, se abre sólo la
 * ficha del próximo pase de cada una y su hora vale para todos: una ficha por
 * fiesta y no una por noche. La colección de Barcelona es otra y no se mira.
 */
final class ShokoSource implements EventSource
{
    private const BASE       = 'https://shokomadrid.com';
    private const COLLECTION = self::BASE . '/collections/eventos-shoko-madrid/products.json?limit=250';
    private const LAT        = 40.4087463;
    private const LNG        = -3.7106359;
    private const ADDRESS    = 'Calle de Toledo, 86, 28005 Madrid';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'shoko';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $json = $this->web->get(self::COLLECTION);
        $data = $json === null ? null : json_decode($json, true);
        if (!is_array($data) || !isset($data['products'])) {
            throw new \RuntimeException('No se pudo leer la agenda de Shôko');
        }

        $tz     = new \DateTimeZone('Europe/Madrid');
        $today  = new \DateTimeImmutable('today', $tz);
        $series = [];

        foreach ($data['products'] as $p) {
            // «HALLOWEEN - PURE SHÔKO   Saturday, 31.10.2026»: el nombre es lo
            // de antes del día de la semana. El `handle` no sirve para la fecha:
            // lo reutilizan y a veces no casa con el título.
            if (!preg_match('/^(.*?)[\s\-–]+(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday),?\s*(\d{1,2})\.(\d{1,2})\.(\d{4})\s*$/iu', trim((string) ($p['title'] ?? '')), $m)
                || !checkdate((int) $m[3], (int) $m[2], (int) $m[4])) {
                continue;
            }
            $day = $today->setDate((int) $m[4], (int) $m[3], (int) $m[2]);
            if ($day < $today) {
                continue;
            }

            $name = trim(preg_replace('/\s+/u', ' ', $m[1]) ?? '', " -–");
            $series[$this->slug($name)][] = [
                'name'   => $name,
                'day'    => $day,
                'handle' => (string) ($p['handle'] ?? ''),
                'image'  => $p['images'][0]['src'] ?? null,
            ];
        }

        $performances = [];
        foreach ($series as $id => $passes) {
            usort($passes, fn (array $a, array $b) => $a['day'] <=> $b['day']);
            [$time, $description] = $this->details($passes[0]['handle']);

            foreach ($passes as $pass) {
                $url   = self::BASE . '/es/products/' . $pass['handle'];
                $start = $time !== null ? $pass['day']->setTime($time[0], $time[1]) : $pass['day'];
                [$subcategory, $subtype] = $this->classify($pass['name']);

                $performances[] = new ScrapedEvent(
                    source: $this->name(),
                    // La fiesta, no la noche: todas sus noches comparten id y
                    // `Shows` las junta en una con su rango.
                    externalId: $id,
                    title: $pass['name'],
                    start: $start,
                    // Sin hora, el día entero.
                    end: $time === null ? $pass['day']->setTime(23, 59) : null,
                    city: $this->city(),
                    venueName: 'Shôko Madrid',
                    latitude: self::LAT,
                    longitude: self::LNG,
                    link: $url,
                    linkAction: 'buy',
                    description: $description,
                    imageUrl: is_string($pass['image']) ? $pass['image'] : null,
                    detailUrl: $url,
                    venueAddress: self::ADDRESS,
                    subcategory: $subcategory,
                    subtype: $subtype,
                );
            }
        }

        return Shows::group($performances);
    }

    /** Todo viene en `fetch`: la ficha ya se abrió para la hora. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: 'Shôko Madrid',
            city: $this->city(),
            categorySlug: 'nightlife',
            latitude: self::LAT,
            longitude: self::LNG,
            address: self::ADDRESS,
            website: self::BASE . '/es',
        );
    }

    /**
     * Hora y descripción de la cabecera de la ficha: «Drill UK trap, R&B… |
     * A partir de las 23:45» (o «18.00» en el tardeo).
     *
     * @return array{0: array{0: int, 1: int}|null, 1: ?string}
     */
    private function details(string $handle): array
    {
        $html = $handle === '' ? null : $this->web->get(self::BASE . '/es/products/' . $handle);
        if ($html === null) {
            return [null, null];
        }

        $xp   = Html::xpath($html);
        $line = Html::text($xp, '//section[' . Html::hasClass('eventbaner') . ']//*[' . Html::hasClass('text_position_abs') . ']/p');
        if ($line === null) {
            return [null, null];
        }

        $time = preg_match('/(\d{1,2})[:.](\d{2})/', $line, $m) && (int) $m[1] < 24 ? [(int) $m[1], (int) $m[2]] : null;

        return [$time, Html::clean($line)];
    }

    /**
     * Las fiestas fijas son noches de discoteca; el tardeo, tardeo, y las
     * noches señaladas (Halloween…) se dicen en el nombre.
     *
     * @return array{0: string, 1: string}
     */
    private function classify(string $name): array
    {
        return match (true) {
            (bool) preg_match('/\btardeo\b/iu', $name)                                   => ['events-nightlife', 'events-nightlife-tardeo'],
            (bool) preg_match('/halloween|carnaval|nochevieja|fin de a[ñn]o/iu', $name) => ['events-nightlife', 'events-nightlife-theme-parties'],
            default                                                                      => ['events-nightlife', 'events-nightlife-clubs'],
        };
    }

    private function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim(preg_replace('/[^a-z0-9]+/', '-', $text) ?? '', '-');
    }
}
