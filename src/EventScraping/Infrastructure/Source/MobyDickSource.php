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
 * Moby Dick Club (mobydickclub.com), en Avenida de Brasil: una web en PHP de
 * las de antes, sin datos estructurados ni API. `programacion.php` trae los
 * próximos meses enteros en una página, con cartel, fecha y hora, precio,
 * texto y enlace de entradas en cada concierto — no hace falta abrir fichas.
 *
 * La fecha viene sin año («VIERNES 25 de SEPTIEMBRE. 21h.»): lo pone
 * `SpanishDate`. Las rutas de la web son relativas a la raíz.
 */
final class MobyDickSource implements EventSource
{
    private const SITE    = 'https://www.mobydickclub.com';
    private const LISTING = self::SITE . '/programacion.php';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'moby-dick';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::LISTING);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la programación de Moby Dick');
        }

        $xp           = Html::xpath($html);
        $performances = [];

        // Cada concierto es un `div#grupoconcierto` (el id se repite).
        foreach ($xp->query('//div[@id="grupoconcierto"]') as $card) {
            $title   = Html::text($xp, './/a[' . Html::hasClass('conciertotitulo') . ']', $card);
            $when    = (string) Html::text($xp, './/*[' . Html::hasClass('conciertofecha') . ']', $card);
            $image   = Html::attr($xp, './/div[@id="fotothumbprog"]//img', 'src', $card);
            $detail  = Html::absolute(Html::attr($xp, './/a[' . Html::hasClass('botonampliar') . ']', 'href', $card), self::SITE);
            $tickets = Html::attr($xp, './/a[' . Html::hasClass('botonentradas') . ']', 'href', $card);

            if ($title === null || $image === null
                || !preg_match('/(\d{1,2})\s+de\s+([a-záéíóú]+)\.?\s*(?:(\d{1,2})(?:[:.](\d{2}))?\s*h)?/iu', $when, $m)
                || ($month = SpanishDate::month($m[2])) === null) {
                continue;
            }

            $time  = isset($m[3]) && $m[3] !== '' ? sprintf('%d:%s', $m[3], ($m[4] ?? '') ?: '00') : null;
            $start = SpanishDate::build((int) $m[1], $month, $time);
            if ($start === null) {
                continue;
            }

            // El subtítulo va unas veces antes del nombre («Verbena en la
            // Ballena») y otras después («Tributo a Elvis Presley»): se queda
            // en la descripción, delante del texto.
            $subtitle = Html::text($xp, './/*[' . Html::hasClass('conciertopresenta') . ']', $card);
            $text     = Html::clean(implode('. ', array_filter([$subtitle, Html::text($xp, './/div[@id="txtdescriptivo"]', $card)])));

            $performances[] = new ScrapedEvent(
                source: $this->name(),
                // El nombre y no la ficha (`graceland25septiembre2026.php`
                // lleva la fecha): una misma fiesta en varias fechas es un
                // evento con su rango (ver `Shows`).
                externalId: $this->slug($title),
                title: $title,
                start: $start,
                end: $time === null ? $start->setTime(23, 59) : null,
                city: $this->city(),
                venueName: 'Moby Dick Club',
                latitude: 40.4544936,
                longitude: -3.6940414,
                link: $tickets ?? $detail,
                linkAction: $tickets !== null ? 'buy' : 'info',
                description: $text,
                imageUrl: Html::absolute($image, self::SITE),
                detailUrl: $detail,
                venueAddress: 'Avenida de Brasil, 5, 28020 Madrid',
                subcategory: 'events-small-concerts',
            );
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
            name: 'Moby Dick Club',
            city: $this->city(),
            categorySlug: 'nightlife',
            latitude: 40.4544936,
            longitude: -3.6940414,
            address: 'Avenida de Brasil, 5, 28020 Madrid',
            website: self::SITE . '/',
        );
    }

    private function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return mb_substr(trim(preg_replace('/[^a-z0-9]+/', '-', $text) ?? '', '-'), 0, 150);
    }
}
