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
 * Torres Bermejas, tablao de Mesonero Romanos (web en Wix). La portada publica
 * el elenco de cada semana —«21-27 / Septiembre», «28-04 / Septiembre-Oct»— en
 * un repetidor de Wix, con su foto, y el horario de los pases en el texto de
 * arriba («pases a las 17:00 hs. 19:00 hs. y 21:00 hs.»).
 *
 * Una semana es un evento con su rango, como las tandas de Corral de la
 * Morería: el elenco cambia, y un único evento para siempre se quedaría con el
 * de la primera semana y no volvería a importarse al caducar.
 *
 * Wix no pone clases con sentido: cada elemento de una semana lleva en su id el
 * sufijo del elemento del repetidor (`…__item-mqy6xymn`), y por ahí se agrupan.
 * Qué es cada cosa se deduce del texto, no del id del componente, que cambia en
 * cuanto alguien toca la plantilla.
 */
final class TorresBermejasSource implements EventSource
{
    private const BASE    = 'https://www.torresbermejas.com';
    private const LAT     = 40.4198098;
    private const LNG     = -3.7042315;
    private const ADDRESS = 'Calle de Mesonero Romanos, 11, 28013 Madrid';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'torres-bermejas';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::BASE . '/');
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la portada de Torres Bermejas');
        }

        $xp   = Html::xpath($html);
        $page = trim((string) preg_replace('/\s+/u', ' ', $xp->document->textContent));
        // El primer pase del día; sin él, la noche entera.
        $time = preg_match('/pases a las (\d{1,2})[:.](\d{2})/iu', $page, $t) ? [(int) $t[1], (int) $t[2]] : null;

        $passes = [];
        foreach ($this->weeks($xp) as $week) {
            [$first, $last] = $this->range($week['days'], $week['months']) ?? [null, null];
            if ($first === null || $week['image'] === null) {
                continue;
            }

            // Un pase por noche con el id de la semana: `Shows` los junta en
            // uno que empieza en la próxima.
            for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
                $passes[] = new ScrapedEvent(
                    source: $this->name(),
                    externalId: $first->format('Y-m-d'),
                    title: 'Tablao flamenco Torres Bermejas',
                    start: $time !== null ? $day->setTime($time[0], $time[1]) : $day,
                    end: $time !== null ? null : $day->setTime(23, 59),
                    city: $this->city(),
                    venueName: 'Torres Bermejas',
                    latitude: self::LAT,
                    longitude: self::LNG,
                    link: $week['link'] ?? self::BASE . '/',
                    linkAction: $week['link'] !== null ? 'buy' : 'info',
                    description: $week['cast'],
                    imageUrl: $week['image'],
                    detailUrl: self::BASE . '/',
                    venueAddress: self::ADDRESS,
                    subcategory: 'events-flamenco',
                    subtype: 'events-flamenco-tablao',
                );
            }
        }

        return Shows::group($passes);
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
            name: 'Torres Bermejas',
            city: $this->city(),
            categorySlug: 'culture-business',
            latitude: self::LAT,
            longitude: self::LNG,
            address: self::ADDRESS,
            website: self::BASE . '/',
        );
    }

    /**
     * Las semanas del repetidor: días («21-27»), meses («Septiembre-Oct»),
     * foto, elenco y enlace de reserva.
     *
     * @return array<string, array{days: ?string, months: ?string, image: ?string, cast: ?string, link: ?string}>
     */
    private function weeks(\DOMXPath $xp): array
    {
        $weeks = [];

        foreach ($xp->query('//*[contains(@id, "__item-")]') as $node) {
            if (!$node instanceof \DOMElement || !preg_match('/__item-([a-z0-9]+)$/', $node->getAttribute('id'), $m)) {
                continue;
            }
            $week = $weeks[$m[1]] ?? ['days' => null, 'months' => null, 'image' => null, 'cast' => null, 'link' => null];
            $text = trim((string) preg_replace('/[\s\x{200B}]+/u', ' ', $node->textContent));

            if (preg_match('/^\d{1,2}\s*-\s*\d{1,2}$/', $text)) {
                $week['days'] ??= $text;
            } elseif (preg_match('/^\p{L}+(\s*-\s*\p{L}+)?$/u', $text) && SpanishDate::month($text) !== null) {
                $week['months'] ??= $text;
            } elseif (preg_match('/^(al cante|a la guitarra|bailaor)/iu', $text)) {
                $week['cast'] ??= $this->cast($node);
            }
            if ($week['image'] === null && ($src = Html::attr($xp, './/img', 'src', $node)) !== null) {
                $week['image'] = $this->fullImage($src);
            }
            if ($week['link'] === null && preg_match('/^reservar$/iu', $text)) {
                $week['link'] = Html::attr($xp, './descendant-or-self::a[@href]', 'href', $node);
            }

            $weeks[$m[1]] = $week;
        }

        return $weeks;
    }

    /**
     * «28-04» + «Septiembre-Oct» → del 28 de septiembre al 4 de octubre. El año
     * lo pone `SpanishDate`.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    private function range(?string $days, ?string $months): ?array
    {
        if ($days === null || $months === null) {
            return null;
        }
        [$d1, $d2] = array_map('intval', preg_split('/\s*-\s*/', $days) ?: []) + [0, 0];
        $names     = preg_split('/\s*-\s*/', $months) ?: [];
        $m1        = SpanishDate::month($names[0]);
        $m2        = SpanishDate::month($names[count($names) - 1]);
        if ($m1 === null || $m2 === null) {
            return null;
        }

        $first = SpanishDate::build($d1, $m1, null);
        $last  = SpanishDate::build($d2, $m2, null);
        if ($first === null || $last === null) {
            return null;
        }
        // Fin de año: «28-03 Diciembre-Ene».
        if ($last < $first) {
            $last = $last->modify('+1 year');
        }

        // Más de dos semanas es un rango mal leído: mejor un día que un evento
        // que no se acaba.
        return [$first, $last <= $first->modify('+14 days') ? $last : $first];
    }

    /**
     * «Al cante / Jacob / A la guitarra / Manuel Amador / Bailaoras / …», con
     * saltos de línea entre etiqueta y nombres, a «Al cante: Jacob. A la
     * guitarra: Manuel Amador. …».
     */
    private function cast(\DOMElement $node): ?string
    {
        // Los `<br>` de Wix llegan como saltos de línea en el texto.
        $lines = array_values(array_filter(array_map(
            fn (string $l) => trim((string) preg_replace('/[\s\x{00A0}\x{200B}]+/u', ' ', $l)),
            preg_split('/\R/u', $node->textContent) ?: [],
        ), fn (string $l) => $l !== ''));

        $parts = [];
        foreach ($lines as $line) {
            if (preg_match('/^(al cante|a la guitarra|al toque|bailaor(a|es|as)?|coreograf[ií]a|percusi[oó]n|cante|guitarra)$/iu', $line)) {
                $parts[] = [$line, []];
            } elseif ($parts !== []) {
                $parts[count($parts) - 1][1][] = $line;
            }
        }

        return Html::clean(implode(' ', array_map(
            fn (array $p) => sprintf('%s: %s.', $p[0], implode(', ', $p[1])),
            array_filter($parts, fn (array $p) => $p[1] !== []),
        )));
    }

    /**
     * Wix sirve la foto recortada a 554 px y en AVIF. Del id del original se
     * pide una versión que quepa en el cartel vertical (1080×1920), en JPEG.
     */
    private function fullImage(string $src): string
    {
        if (!preg_match('#^(https://static\.wixstatic\.com/media/[^/]+)/v1/.*/([^/]+)$#', $src, $m)) {
            return $src;
        }

        return sprintf('%s/v1/fit/w_1080,h_1920,q_85/%s', $m[1], $m[2]);
    }
}
