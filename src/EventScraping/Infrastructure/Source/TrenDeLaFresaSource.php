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
 * Tren de la Fresa (trendelafresa.es), el tren histórico de Madrid a Aranjuez
 * de la Fundación de los Ferrocarriles Españoles, que sale del Museo del
 * Ferrocarril.
 *
 * La portada enseña los itinerarios en tarjetas, y **sólo los de temporada
 * llevan fechas** («Días de circulación: 3, 4, 10… de octubre y 1 de
 * noviembre», «4, 11 y 18 de octubre, y 1 y 8 de noviembre de 2026»): son los
 * que se leen, uno por itinerario y con todos sus días (`Shows`). Los clásicos
 * —Fresas con Nata, Fresas Reales…— salen todos los días de circulación del
 * tren, pero esos días sólo los cuenta una nota de prensa del museo, no la web
 * del tren: no se leen.
 *
 * La hora sale del «Planning» de la ficha de cada itinerario («De 10:00 a
 * 11:00 horas: Viaje de ida…», «De 18:54 a 20:08 horas: Viaje de vuelta…»), y
 * la foto, de su galería. La web está en Latin-1.
 */
final class TrenDeLaFresaSource implements EventSource
{
    private const SITE = 'https://trendelafresa.es/';

    private const NAME    = 'Tren de la Fresa';
    private const LAT     = 40.3994167;
    private const LNG     = -3.6920258;
    private const ADDRESS = 'Museo del Ferrocarril, Paseo de las Delicias, 61, 28045 Madrid';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'tren-de-la-fresa';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->page(self::SITE);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la portada del Tren de la Fresa');
        }

        $xp     = Html::xpath($html);
        $passes = [];
        foreach ($xp->query('//div[' . Html::hasClass('member') . '][.//h4]') as $card) {
            $title = Html::clean(Html::text($xp, './/h4', $card), 200);
            $href  = Html::attr($xp, './/a[@href]', 'href', $card);
            $when  = Html::text($xp, './/p', $card);
            if ($title === null || $href === null || $when === null) {
                continue;
            }
            $days = $this->dates($when);
            if ($days === []) {
                continue;
            }

            $url    = self::SITE . ltrim($href, '/');
            $detail = $this->page($url);
            $dxp    = $detail !== null ? Html::xpath($detail) : null;
            $text   = $dxp !== null ? (string) Html::text($dxp, '//body') : '';
            $image  = $dxp !== null ? Html::attr($dxp, '//img[contains(@src, "galeria/")]', 'src') : null;
            $image  = $image !== null ? self::SITE . ltrim($image, '/') : Html::attr($xp, './/img', 'src', $card);
            if ($image === null) {
                continue;
            }
            [$from, $to] = $this->schedule($text);
            [$subcategory, $subtype] = $this->classify($title, $text);

            foreach ($days as $day) {
                $passes[] = new ScrapedEvent(
                    source: $this->name(),
                    externalId: basename($href, '.asp'),
                    title: self::NAME . ': ' . $title,
                    start: $from !== null ? $day->setTime($from[0], $from[1]) : $day,
                    end: $to !== null ? $day->setTime($to[0], $to[1]) : $day->setTime(23, 59),
                    city: $this->city(),
                    venueName: self::NAME,
                    latitude: self::LAT,
                    longitude: self::LNG,
                    link: self::SITE . 'venta-billetes.asp',
                    linkAction: 'buy',
                    description: Html::clean(Html::text($xp, './/span', $card), 400),
                    imageUrl: preg_match('#^https?://#', $image) ? $image : self::SITE . ltrim($image, '/'),
                    detailUrl: $url,
                    venueAddress: self::ADDRESS,
                    subcategory: $subcategory,
                    subtype: $subtype,
                );
            }
        }

        return Shows::group($passes);
    }

    /** La ficha ya se leyó en `fetch`: hacían falta la hora y la foto. */
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
            website: self::SITE,
        );
    }

    /** La página en UTF-8: la web sirve Latin-1. */
    private function page(string $url): ?string
    {
        $html = $this->web->get($url);
        if ($html === null) {
            return null;
        }

        if (mb_check_encoding($html, 'UTF-8')) {
            return $html;
        }

        // Y se quita su `<meta charset="iso-8859-1">`: libxml le hace caso
        // antes que a la declaración de `Html::xpath`, y las tildes salían
        // convertidas dos veces («MÃºsica»).
        return (string) preg_replace('/(<meta[^>]*charset=["\']?)iso-8859-1/i', '${1}utf-8', mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1'));
    }

    /**
     * La salida del tren de ida y la llegada del de vuelta. El «Planning»
     * las escribe de dos formas: «De 10:00 a 11:00 horas: Viaje de ida…» y,
     * en los de programa propio, «10:00 h. Viaje en el histórico Tren…»; la
     * vuelta siempre es «De 18:54 a 20:08 horas: Viaje de vuelta».
     *
     * @return array{0: ?array{0: int, 1: int}, 1: ?array{0: int, 1: int}}
     */
    private function schedule(string $text): array
    {
        $from = preg_match('/(\d{1,2})[:.](\d{2})(?:\s*a\s*\d{1,2}[:.]\d{2})?\s*(?:horas|h\.)\s*:?\s*Viaje (?!de vuelta)/u', $text, $m)
            ? [(int) $m[1], (int) $m[2]]
            : null;
        $to = preg_match('/a (\d{1,2})[:.](\d{2}) horas\s*:?\s*Viaje de vuelta/u', $text, $m)
            ? [(int) $m[1], (int) $m[2]]
            : null;

        return [$from, $to];
    }

    /**
     * Lo que la ficha dice pensado para niños (la huerta, con sus calabazas
     * y su tractor) es un plan en familia; lo del festival de música antigua
     * —por su título: la huerta también tiene música country—, un concierto;
     * el resto, una visita guiada. «Coral infantil» o el precio de «Niños» no
     * cuentan: están en todas las fichas.
     *
     * @return array{0: string, 1: ?string}
     */
    private function classify(string $title, string $detail): array
    {
        $detail = mb_strtolower($detail);

        return match (true) {
            (bool) preg_match('/p[uú]blico familiar|actividades l[uú]dicas infantiles|con niños/u', $detail) => ['events-kids', 'events-kids-family-plans'],
            (bool) preg_match('/m[uú]sica|concierto/iu', $title)                                            => ['events-small-concerts', null],
            default                                                                                         => ['events-experiences', 'events-experiences-guided-tours'],
        };
    }

    /**
     * «3, 4, 10 y 31 de octubre y 1 de noviembre»: los días esperan al mes que
     * llega después. Sin año (o con el de la temporada al final, que se quita
     * para no leerlo como un día): lo deduce `SpanishDate`.
     *
     * @return list<\DateTimeImmutable>
     */
    private function dates(string $text): array
    {
        $text = (string) preg_replace('/\b\d{4}\b/', '', $text);
        preg_match_all('/(\d{1,2})|de\s+([a-záéíóú]+)/iu', $text, $tokens, \PREG_SET_ORDER);

        $pending = [];
        $out     = [];
        foreach ($tokens as $t) {
            if (($t[1] ?? '') !== '') {
                $pending[] = (int) $t[1];
                continue;
            }
            $month = SpanishDate::month($t[2] ?? '');
            if ($month === null) {
                continue;
            }
            foreach ($pending as $day) {
                $date = SpanishDate::build($day, $month, null);
                if ($date !== null) {
                    $out[] = $date;
                }
            }
            $pending = [];
        }

        return $out;
    }
}
