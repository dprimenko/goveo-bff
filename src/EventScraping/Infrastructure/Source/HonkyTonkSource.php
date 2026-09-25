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
 * Honky Tonk (clubhonky.com), bar de conciertos de rock y blues en Chamberí.
 *
 * La programación es una página por mes (`/programacion/?date=2026-10`): cada
 * concierto lleva el día completo en `data-id` («02/10/2026»), la foto, la hora
 * y el enlace a su ficha. Se leen el mes en curso y el siguiente, que cubren
 * la ventana de 30 días. La API de WordPress (`/wp/v2/conciertos`) tiene las
 * fichas pero no la fecha.
 *
 * - **La hora es de madrugada**: «Viernes 04 · 00:30h» es el viernes por la
 *   noche, ya pasadas las doce. Se deja a las 23:59 del día que anuncian, como
 *   las sesiones de club del resto de salas, para que caiga en su noche.
 * - Lo fijo de cada semana (la jam de los jueves, el open mic de los martes, los
 *   tributos que repiten) sale como un evento con su rango (ver `Shows`).
 * - Entradas no vende: se paga en la puerta. El enlace es la ficha.
 */
final class HonkyTonkSource implements EventSource
{
    private const SITE    = 'https://clubhonky.com';
    private const NAME    = 'Honky Tonk';
    private const LAT     = 40.4302247;
    private const LNG     = -3.6976516;
    private const ADDRESS = 'Calle de Covarrubias, 24, 28010 Madrid';

    /** Hasta esta hora, el concierto es de la noche del día anterior. */
    private const LATE_NIGHT_UNTIL = 6;

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'honky-tonk';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $tz     = new \DateTimeZone('Europe/Madrid');
        $month  = new \DateTimeImmutable('first day of this month', $tz);
        $events = [];

        foreach ([$month, $month->modify('+1 month')] as $i => $m) {
            $html = $this->web->get(sprintf('%s/programacion/?date=%s', self::SITE, $m->format('Y-m')));
            if ($html === null) {
                // El mes que viene puede no estar publicado aún.
                if ($i === 0) {
                    throw new \RuntimeException('No se pudo descargar la programación del Honky Tonk');
                }
                break;
            }

            $xp = Html::xpath($html);
            foreach ($xp->query('//div[' . Html::hasClass('item') . '][@data-id]') as $item) {
                if (!$item instanceof \DOMElement) {
                    continue;
                }
                $title  = $this->title(Html::text($xp, './/h2', $item));
                $start  = $this->start($item->getAttribute('data-id'), Html::text($xp, './/*[' . Html::hasClass('hora_concierto') . ']', $item), $tz);
                $detail = Html::attr($xp, './/h2//a', 'href', $item);
                $image  = Html::attr($xp, './/img', 'src', $item);
                if ($title === null || $start === null || $detail === null) {
                    continue;
                }

                $events[] = new ScrapedEvent(
                    source: $this->name(),
                    externalId: $this->slug($title),
                    title: $title,
                    start: $start,
                    end: null,
                    city: $this->city(),
                    venueName: self::NAME,
                    latitude: self::LAT,
                    longitude: self::LNG,
                    link: $detail,
                    linkAction: 'info',
                    imageUrl: Html::absolute($image, self::SITE),
                    detailUrl: $detail,
                    venueAddress: self::ADDRESS,
                    subcategory: 'events-small-concerts',
                );
            }
        }

        return Shows::group($events);
    }

    /** La descripción, de la ficha (el listado sólo trae el nombre). */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        $description = Html::attr(Html::xpath($html), '//meta[@property="og:description"]', 'content');
        // WordPress corta el extracto con «[…]».
        $description = $description === null ? null : trim((string) preg_replace('/\s*\[(…|&hellip;|\.\.\.)\]\s*$/u', '…', $description));

        return $event->withDetails(null, Html::clean($description));
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

    /** Sin la marca «[recomendado]» de la sala; el resto («[tributo…]») informa. */
    private function title(?string $raw): ?string
    {
        $title = Html::clean($raw, 200);
        if ($title === null) {
            return null;
        }
        $title = trim((string) preg_replace('/\s*\[recomendado\]/iu', '', $title));

        return $title === '' ? null : $title;
    }

    /** `02/10/2026` y «00:30h». */
    private function start(string $date, ?string $time, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($date), $d) || !checkdate((int) $d[2], (int) $d[1], (int) $d[3])) {
            return null;
        }

        $day = (new \DateTimeImmutable('now', $tz))->setDate((int) $d[3], (int) $d[2], (int) $d[1])->setTime(0, 0);
        if ($time === null || !preg_match('/(\d{1,2})[:.](\d{2})/', $time, $t)) {
            return $day;
        }

        return (int) $t[1] < self::LATE_NIGHT_UNTIL ? $day->setTime(23, 59) : $day->setTime((int) $t[1], (int) $t[2]);
    }

    private function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return mb_substr(trim(preg_replace('/[^a-z0-9]+/', '-', $text) ?? '', '-'), 0, 200);
    }
}
