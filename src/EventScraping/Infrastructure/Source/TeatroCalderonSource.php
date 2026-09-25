<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Teatro Calderón (teatrocalderonmadrid.com): la cartelera trae el cartel, el
 * rango de fechas («Desde 18/09/2026 - 29/11/2026») y el enlace de compra en la
 * tarjeta. No trae hora, así que cada evento dura su día entero.
 *
 * Lírico —la terraza del teatro— sale en la misma cartelera y está en el mismo
 * edificio: comparte coordenadas y dueño.
 *
 * El identificador es el nombre del fichero del cartel (`1788882048_1.jpg`),
 * que es lo único numérico y estable de la tarjeta.
 */
final class TeatroCalderonSource implements EventSource
{
    private const LISTING = 'https://teatrocalderonmadrid.com/es/cartelera';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'calderon';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::LISTING);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la cartelera del Teatro Calderón');
        }

        $xp   = Html::xpath($html);
        $tz   = new \DateTimeZone('Europe/Madrid');
        $seen = [];

        // Cada tarjeta es el bloque que contiene a la vez el cartel y el título.
        foreach ($xp->query('//div[.//img and .//h3 and ' . Html::hasClass('rounded-lg') . ' and ' . Html::hasClass('overflow-hidden') . ']') as $card) {
            $title = Html::text($xp, './/h3', $card);
            $img   = Html::attr($xp, './/img', 'src', $card);
            $dates = (string) Html::text($xp, './/span[contains(., "/20")]', $card);

            if ($title === null || $img === null || !preg_match_all('#(\d{2})/(\d{2})/(\d{4})#', $dates, $m, \PREG_SET_ORDER)) {
                continue;
            }

            $id = pathinfo((string) parse_url($img, \PHP_URL_PATH), \PATHINFO_FILENAME);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $start = (new \DateTimeImmutable('now', $tz))->setDate((int) $m[0][3], (int) $m[0][2], (int) $m[0][1])->setTime(0, 0);
            $last  = isset($m[1]) ? $start->setDate((int) $m[1][3], (int) $m[1][2], (int) $m[1][1]) : $start;

            $tickets = Html::attr($xp, './/a[contains(@href, "tickets") or contains(@href, "entradas")]', 'href', $card);
            $venue   = (string) Html::text($xp, './/span[' . Html::hasClass('truncate') . ' and not(contains(., "/20"))][last()]', $card);

            yield new ScrapedEvent(
                source: $this->name(),
                externalId: $id,
                title: $title,
                start: $start,
                end: $last->setTime(23, 59),
                city: $this->city(),
                venueName: 'Teatro Calderón',
                latitude: 40.4140434,
                longitude: -3.7035111,
                link: $tickets ?? self::LISTING,
                linkAction: $tickets !== null ? 'buy' : 'info',
                description: str_contains(mb_strtolower($venue), 'lírico') ? 'En Lírico, la terraza del Teatro Calderón.' : null,
                imageUrl: $img,
                subcategory: 'events-stage',
                subtype: str_contains(mb_strtolower($title), 'musical') ? 'events-stage-musicals' : 'events-stage-theater',
            );
        }
    }

    /** La tarjeta ya lo trae todo; no hay ficha que abrir. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    /**
     * Todo lo de esta fuente es de la misma sala. El avatar, el escaparate, la
     * descripción y el teléfono los saca `WebsiteProfile` de su web.
     */
    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: 'Teatro Calderón',
            city: $this->city(),
            categorySlug: 'culture-business',
            latitude: 40.4140434,
            longitude: -3.7035111,
            address: 'Calle de Atocha, 18, 28012 Madrid',
            website: 'https://teatrocalderonmadrid.com/es',
        );
    }
}
