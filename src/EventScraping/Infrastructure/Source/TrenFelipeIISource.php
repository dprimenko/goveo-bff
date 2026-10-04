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
 * Tren de Felipe II (trenesturisticos.es, de Alsa), el tren histórico de
 * Príncipe Pío a San Lorenzo de El Escorial, con animación a bordo.
 *
 * La ficha del tren tiene su «Calendario» escrito a mano, mes y días
 * («Octubre» y debajo «3, 4, 10 ,11, 12…»), y la de cada pack el horario
 * («Salida: 10:20h / Regreso: 17:25h»): se lee la ficha y el primer pack.
 * Es un evento, la temporada entera con todos sus días (`Shows`). Sin año:
 * lo deduce `SpanishDate`. Los billetes se venden en la propia web.
 */
final class TrenFelipeIISource implements EventSource
{
    private const SITE = 'https://www.trenesturisticos.es';
    private const PAGE = self::SITE . '/trenes/tren-de-felipe-ii';

    private const NAME    = 'Tren de Felipe II';
    private const LAT     = 40.4214673;
    private const LNG     = -3.7189925;
    private const ADDRESS = 'Estación de Príncipe Pío, Paseo de la Florida, s/n, 28008 Madrid';

    private const MONTHS = '(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'tren-felipe-ii';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::PAGE);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la ficha del Tren de Felipe II');
        }

        $xp    = Html::xpath($html);
        $text  = (string) Html::text($xp, '//body');
        $image = Html::absolute(Html::attr($xp, '//img[' . Html::hasClass('c-banner__image') . ']', 'src'), self::SITE);
        $pos   = mb_stripos($text, 'Calendario');
        if ($image === null || $pos === false) {
            return [];
        }
        // Hasta «Tarifas y fechas sujetas a disponibilidad», que cierra el calendario.
        $calendar = mb_substr($text, $pos, 600);
        $calendar = mb_substr($calendar, 0, mb_stripos($calendar, 'Tarifas') ?: 600);

        // La hora, en el primer pack.
        $pack = Html::attr($xp, '//a[contains(@href, "/trenes/pack-")]', 'href');
        $time = null;
        if ($pack !== null && ($packHtml = $this->web->get(Html::absolute($pack, self::SITE) ?? '')) !== null
            && preg_match('/Salida:\s*(\d{1,2})[:.](\d{2})/u', (string) Html::text(Html::xpath($packHtml), '//body'), $t)) {
            $time = [(int) $t[1], (int) $t[2]];
        }

        $description = Html::clean(Html::attr($xp, '//meta[@name="description"]', 'content')
            ?? Html::attr($xp, '//meta[@property="og:description"]', 'content'), 400);

        $passes = [];
        preg_match_all('/' . self::MONTHS . '\s*:?\s*([\d\s,y]+)/iu', $calendar, $months, \PREG_SET_ORDER);
        foreach ($months as $m) {
            $month = SpanishDate::month($m[1]);
            preg_match_all('/\d{1,2}/', $m[2], $days);
            foreach ($days[0] as $d) {
                $day = $month !== null ? SpanishDate::build((int) $d, $month, null) : null;
                if ($day === null) {
                    continue;
                }
                $passes[] = new ScrapedEvent(
                    source: $this->name(),
                    externalId: 'tren-de-felipe-ii',
                    title: self::NAME,
                    start: $time !== null ? $day->setTime($time[0], $time[1]) : $day,
                    end: $time !== null ? null : $day->setTime(23, 59),
                    city: $this->city(),
                    venueName: self::NAME,
                    latitude: self::LAT,
                    longitude: self::LNG,
                    link: self::PAGE,
                    linkAction: 'buy',
                    description: $description,
                    imageUrl: $image,
                    detailUrl: self::PAGE,
                    venueAddress: self::ADDRESS,
                    subcategory: 'events-kids',
                    subtype: 'events-kids-family-plans',
                );
            }
        }

        return Shows::group($passes);
    }

    /** La ficha ya se leyó en `fetch`. */
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
            website: self::PAGE,
        );
    }
}
