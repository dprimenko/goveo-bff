<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Museo de las Ilusiones de Madrid (museumofillusions.es): **sólo sus talleres
 * infantiles con fecha**. El museo en sí abre todos los días sin más, y eso no
 * es un evento.
 *
 * La lista sale de la API de WordPress (`current-event`, que no trae fechas) y
 * las fechas, de la cabecera de cada ficha («octubre 3 - octubre 4, 2026»,
 * «3:00 p. m.- 6:00 p. m.»).
 *
 * - ⚠️ En un taller de todos los fines de semana del mes, la cabecera sólo dice
 *   el primero; el resto está en el texto («Fecha: todos los sábados y domingos
 *   de octubre»). Se lee esa frase para alargarlo hasta fin de mes, con sus
 *   días de la semana.
 * - La API devuelve también los talleres pasados (el del verano): los quita la
 *   ventana de fechas.
 * - El taller va incluido en la entrada: el botón es la compra de la entrada
 *   del museo, en su web.
 */
final class MuseoIlusionesSource implements EventSource
{
    private const SITE    = 'https://museumofillusions.es';
    private const API     = self::SITE . '/wp-json/wp/v2/current-event?per_page=30&_fields=link,title';
    private const TICKETS = self::SITE . '/entradas/';
    private const NAME    = 'Museo de las Ilusiones Madrid';
    private const LAT     = 40.413276;
    private const LNG     = -3.7038658;
    private const ADDRESS = 'Calle del Doctor Cortezo, 8, 28012 Madrid';

    private const MONTHS = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
        'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
    ];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'museo-ilusiones';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $json = $this->web->get(self::API);
        $list = $json === null ? null : json_decode($json, true);
        if (!is_array($list)) {
            throw new \RuntimeException('No se pudo descargar la lista de talleres del Museo de las Ilusiones');
        }

        $events = [];
        foreach ($list as $item) {
            $url = is_array($item) && is_string($item['link'] ?? null) ? $item['link'] : null;
            if ($url === null || !str_contains($url, '/eventos/') || ($html = $this->web->get($url)) === null) {
                continue;
            }

            $xp    = Html::xpath($html);
            $title = Html::clean(Html::text($xp, '//section[' . Html::hasClass('current-events-cover') . ']//*[' . Html::hasClass('title') . ']'), 200)
                ?? Html::clean(html_entity_decode((string) ($item['title']['rendered'] ?? ''), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), 200);
            $dates = Html::text($xp, '//section[' . Html::hasClass('current-events-cover') . ']//div[' . Html::hasClass('date') . ']');
            $hours = Html::text($xp, '//section[' . Html::hasClass('current-events-cover') . ']//div[' . Html::hasClass('time') . ']');
            $range = $this->range($dates, $hours);
            $image = Html::attr($xp, '//meta[@property="og:image"]', 'content');
            if ($title === null || $range === null || $image === null) {
                continue;
            }

            [$start, $end, $weekdays] = $this->wholeMonth($range, mb_strtolower(Html::text($xp, '//main') ?? Html::text($xp, '//body') ?? ''));

            $events[] = new ScrapedEvent(
                source: $this->name(),
                externalId: trim((string) parse_url($url, \PHP_URL_PATH), '/'),
                title: $title,
                start: $start,
                end: $end,
                city: $this->city(),
                venueName: self::NAME,
                latitude: self::LAT,
                longitude: self::LNG,
                link: self::TICKETS,
                linkAction: 'buy',
                description: Html::clean(Html::attr($xp, '//meta[@property="og:description"]', 'content')),
                imageUrl: $image,
                detailUrl: $url,
                weekdays: $weekdays,
                venueAddress: self::ADDRESS,
                subcategory: 'events-kids',
                subtype: 'events-kids-workshops',
            );
        }

        return $events;
    }

    /** Ya se abrió la ficha en `fetch` (las fechas sólo están ahí). */
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
            categorySlug: 'experiences',
            latitude: self::LAT,
            longitude: self::LNG,
            address: self::ADDRESS,
            website: self::SITE . '/',
        );
    }

    /**
     * «octubre 3 - octubre 4, 2026» y «3:00 p. m.- 6:00 p. m.»: inicio con la
     * hora de empezar y fin con la de terminar, el último día.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    private function range(?string $dates, ?string $hours): ?array
    {
        if ($dates === null || !preg_match_all('/(\p{L}+)\s+(\d{1,2})(?:,\s*(\d{4}))?/u', mb_strtolower($dates), $parts, \PREG_SET_ORDER)) {
            return null;
        }

        $year = null;
        foreach ($parts as $p) {
            $year ??= isset($p[3]) && $p[3] !== '' ? (int) $p[3] : null;
        }
        $year ??= (int) date('Y');

        $days = [];
        foreach ($parts as $p) {
            $month = self::MONTHS[$p[1]] ?? null;
            if ($month === null || !checkdate($month, (int) $p[2], $year)) {
                return null;
            }
            $days[] = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->setDate($year, $month, (int) $p[2])->setTime(0, 0);
        }

        $first = $days[0];
        $last  = $days[count($days) - 1];
        if ($last < $first) {
            // «diciembre 28 - enero 3, 2027»: el año escrito es el del fin.
            $first = $first->modify('-1 year');
        }

        preg_match_all('/(\d{1,2}):(\d{2})\s*([ap])\.?\s*m/u', mb_strtolower((string) $hours), $t, \PREG_SET_ORDER);
        $at = fn (\DateTimeImmutable $d, array $h) => $d->setTime(((int) $h[1] % 12) + ($h[3] === 'p' ? 12 : 0), (int) $h[2]);

        return [
            isset($t[0]) ? $at($first, $t[0]) : $first,
            isset($t[1]) ? $at($last, $t[1]) : $last->setTime(23, 59),
        ];
    }

    /**
     * «todos los sábados y domingos de octubre» (o «todos los fines de semana
     * de octubre»): hasta el último día de ese mes, sólo esos días.
     *
     * @param array{0: \DateTimeImmutable, 1: \DateTimeImmutable} $range
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: list<int>|null}
     */
    private function wholeMonth(array $range, string $text): array
    {
        [$start, $end] = $range;
        if (!preg_match('/todos los (s[áa]bados y domingos|fines de semana|s[áa]bados|domingos) de (\p{L}+)/u', $text, $m)
            || ($month = self::MONTHS[$m[2]] ?? null) !== (int) $start->format('n')
        ) {
            return [$start, $end, null];
        }

        $weekdays = match (true) {
            str_starts_with($m[1], 'domingos') => [7],
            str_contains($m[1], 'domingos'), str_starts_with($m[1], 'fines') => [6, 7],
            default => [6],
        };

        $lastDay = $start->modify('last day of this month');

        return [$start, $lastDay->setTime((int) $end->format('G'), (int) $end->format('i')), $weekdays];
    }
}
