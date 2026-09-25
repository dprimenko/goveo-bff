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
 * Teatro de la Zarzuela (teatrodelazarzuela.inaem.gob.es), un Joomla con el
 * calendario JEvents. Su vista de mes es una tabla con una fila por función
 * —día, espectáculo y hora—, y se lee la de este mes y la del siguiente.
 *
 * Las rutas del calendario llevan «temporada-2021-2022» aunque enseñen la
 * temporada en curso: es el menú desde el que se montó, no un calendario viejo.
 * Por eso se pide por `index.php?option=com_jevents`, que no depende de él.
 *
 * Las funciones escolares van enlazadas a la portada (`/`) y se descartan: no
 * son para el público.
 */
final class TeatroZarzuelaSource implements EventSource
{
    private const HOME  = 'https://teatrodelazarzuela.inaem.gob.es';
    private const MONTH = self::HOME . '/index.php?option=com_jevents&task=month.calendar&year=%d&month=%d&day=1&Itemid=723&tmpl=component';

    /**
     * La web corta con un 403 a partir de unas ocho peticiones seguidas (se
     * midió: a una cada 0,4 s, la novena ya falla), así que entre ficha y
     * ficha se esperan 4 s, más de lo que espera `WebPage`.
     */
    private const DETAIL_PAUSE = 4_000_000;

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'teatro-zarzuela';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $tz           = new \DateTimeZone('Europe/Madrid');
        $month        = new \DateTimeImmutable('first day of this month', $tz);
        $performances = [];

        for ($i = 0; $i < 2; ++$i, $month = $month->modify('+1 month')) {
            $html = $this->web->get(sprintf(self::MONTH, (int) $month->format('Y'), (int) $month->format('n')));
            if ($html === null) {
                if ($i === 0) {
                    throw new \RuntimeException('No se pudo descargar el calendario del Teatro de la Zarzuela');
                }
                break;
            }

            $xp = Html::xpath($html);
            foreach ($xp->query('//tr[td[@headers="col3b"]]') as $row) {
                $day   = (int) Html::text($xp, './td[@headers="col2b"]', $row);
                $title = Html::clean(Html::text($xp, './td[@headers="col3b"]', $row), 200);
                $href  = Html::attr($xp, './td[@headers="col3b"]//a', 'href', $row);
                $time  = (string) Html::text($xp, './td[@headers="col4b"]', $row);
                if ($title === null || $href === null || trim($href, '/') === '' || !checkdate((int) $month->format('n'), $day, (int) $month->format('Y'))) {
                    continue;
                }

                $page  = (string) Html::absolute($href, self::HOME);
                $start = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day)->setTime(0, 0);
                if (preg_match('/(\d{1,2}):(\d{2})/', $time, $t)) {
                    $start = $start->setTime((int) $t[1], (int) $t[2]);
                }

                $performances[] = new ScrapedEvent(
                    source: $this->name(),
                    // El espectáculo, no la función: `Shows` junta sus pases.
                    externalId: basename((string) parse_url($page, \PHP_URL_PATH)),
                    title: $title,
                    start: $start,
                    end: null,
                    city: $this->city(),
                    venueName: 'Teatro de la Zarzuela',
                    latitude: 40.4171895,
                    longitude: -3.6969903,
                    link: $page,
                    linkAction: 'info',
                    detailUrl: $page,
                    venueAddress: 'Calle de Jovellanos, 4, 28014 Madrid',
                    subcategory: 'events-stage',
                    // El ciclo va en la ruta (`/temporada/danza-2026-2027/…`):
                    // lírica, conciertos y lied se quedan en Escena.
                    subtype: str_contains($page, '/danza-') ? 'events-stage-dance' : null,
                );
            }
        }

        return Shows::group($performances);
    }

    /**
     * El cartel, la sinopsis y la compra, de la ficha del espectáculo. El cartel
     * sale en miniatura (`…_S.jpg`); la caché de K2 guarda el mismo en grande
     * (`…_XL.jpg`, 900 px de ancho).
     */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        usleep(self::DETAIL_PAUSE);
        if ($event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        $xp      = Html::xpath($html);
        $poster  = Html::attr($xp, '//img[' . Html::hasClass('fotoFicha') . ']', 'src');
        $tickets = Html::attr($xp, '//div[' . Html::hasClass('flt_right') . ']//a[contains(., "Comprar entradas")]', 'href');

        // La sinopsis no tiene marca propia: es el párrafo más largo de la
        // ficha, entre la autoría, la duración y los logos.
        $description = null;
        foreach ($xp->query('//div[' . Html::hasClass('flt_right') . ']//p') as $p) {
            $text = Html::clean($p->textContent, 400);
            if ($text !== null && mb_strlen($text) > mb_strlen((string) $description)) {
                $description = $text;
            }
        }

        return $event->withDetails(
            $poster !== null ? Html::absolute((string) preg_replace('/_S\.jpg$/', '_XL.jpg', $poster), self::HOME) : null,
            $description,
            $tickets !== null && preg_match('#^https?://#', $tickets) ? $tickets : null,
            $tickets !== null && preg_match('#^https?://#', $tickets) ? 'buy' : null,
        );
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: 'Teatro de la Zarzuela',
            city: $this->city(),
            categorySlug: 'culture-business',
            latitude: 40.4171895,
            longitude: -3.6969903,
            address: 'Calle de Jovellanos, 4, 28014 Madrid',
            website: 'https://teatrodelazarzuela.inaem.gob.es/es',
        );
    }
}
