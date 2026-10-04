<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Mercado de Motores (mercadodemotores.es), el mercadillo de diseño, artesanía,
 * vintage y comida del Museo del Ferrocarril, el segundo fin de semana de cada
 * mes.
 *
 * No tiene agenda: la portada dice a mano las ediciones que vienen («Los
 * siguientes serán: 10 y 11 de octubre · 7 y 8 de noviembre…») y el horario
 * («Sábado: 11.00 a 22.00 · Domingo: 11.00 a 21.00»). Se lee ese texto.
 *
 * - **Un evento por edición**, del sábado a la apertura al domingo al cierre:
 *   cada mes cambian los puestos, no es el mismo plan repetido.
 * - Sin `og:image`: el cartel es la foto grande de la portada (la del fondo de
 *   su bloque principal) y, si cambian la maqueta, una fija del mercado.
 * - La sala es el propio mercado, no el museo: es lo que la gente busca, y el
 *   museo tiene su propia programación.
 */
final class MercadoMotoresSource implements EventSource
{
    private const SITE     = 'https://mercadodemotores.es';
    private const NAME     = 'Mercado de Motores';
    private const LAT      = 40.3994167;
    private const LNG      = -3.6920258;
    private const ADDRESS  = 'Museo del Ferrocarril, Paseo de las Delicias, 61, 28045 Madrid';
    private const FALLBACK = self::SITE . '/wp-content/uploads/2021/10/Foto-ganadora-Mercado-de-Motores-septiembre.jpg';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'mercado-motores';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::SITE . '/');
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la portada del Mercado de Motores');
        }

        $xp   = Html::xpath($html);
        $text = Html::clean(Html::text($xp, '//body'), 100_000) ?? '';

        // «Sábado: 11.00 a 22.00» / «Sábado 11h a 22h y domingo 11h a 21h».
        $opens  = preg_match('/s[áa]bado:?\s*(\d{1,2})\s*[.:h]/iu', $text, $m) ? (int) $m[1] : 11;
        $closes = preg_match('/domingo:?\s*\d{1,2}\s*[.:h]\s*\d*\s*h?\s*a\s*(\d{1,2})/iu', $text, $m) ? (int) $m[1] : 21;

        $image       = $this->image($html);
        $description = Html::clean(Html::attr($xp, '//meta[@property="og:description"]', 'content'));
        $events      = [];

        preg_match_all('/(\d{1,2})\s+y\s+(\d{1,2})\s+de\s+(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)/iu', $text, $all, \PREG_SET_ORDER);
        foreach ($all as [, $saturday, $sunday, $monthName]) {
            $month = SpanishDate::month($monthName);
            $start = $month !== null ? SpanishDate::build((int) $saturday, $month, sprintf('%02d:00', $opens)) : null;
            if ($start === null || $start->format('N') !== '6') {
                // Sólo fines de semana de sábado y domingo: otra cosa es texto
                // de la página que se parece («6 y 7 de junio» de una noticia).
                continue;
            }

            $id = 'edicion-' . $start->format('Y-m');
            $events[$id] ??= new ScrapedEvent(
                source: $this->name(),
                externalId: $id,
                title: sprintf('%s · %d y %d de %s', self::NAME, (int) $saturday, (int) $sunday, mb_strtolower($monthName)),
                start: $start,
                end: $start->modify('+1 day')->setTime($closes, 0),
                city: $this->city(),
                venueName: self::NAME,
                latitude: self::LAT,
                longitude: self::LNG,
                link: self::SITE . '/',
                linkAction: 'info',
                description: $description,
                imageUrl: $image,
                detailUrl: self::SITE . '/',
                weekdays: [6, 7],
                venueAddress: self::ADDRESS,
                subcategory: 'events-markets',
                subtype: 'events-markets-vintage-crafts',
            );
        }

        return array_values($events);
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
            name: self::NAME,
            city: $this->city(),
            categorySlug: 'crafts',
            latitude: self::LAT,
            longitude: self::LNG,
            address: self::ADDRESS,
            website: self::SITE . '/',
        );
    }

    /** La primera foto de fondo de la portada (los logos y banners son PNG). */
    private function image(string $html): string
    {
        return preg_match('#background-image:\s*url\([\'"]?(https?://[^\'")]+\.jpe?g)#i', $html, $m) ? $m[1] : self::FALLBACK;
    }
}
