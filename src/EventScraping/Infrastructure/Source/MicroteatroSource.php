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
 * Microteatro por Dinero (microteatro.es), en Malasaña: obras de 15 minutos
 * en salas pequeñas, varias a la vez y en sesión continua.
 *
 * Su web remite a su taquilla (`taquilla.microteatro.es`), que es la suya, y
 * ahí la programación **de un día** (`?fecha=dd.mm.aaaa`) lista cada obra con
 * cartel, sinopsis, género y las horas de sus pases. Sin fecha sólo enseña las
 * obras, sin cuándo, así que se recorre día a día hasta el horizonte (un día
 * cerrado sale vacío).
 *
 * - **Una obra, un evento** con su rango (ver `Shows`): cada obra está en
 *   cartel un mes y se repite cada noche hasta siete veces.
 * - Cada día el evento va del primer pase al final del último (15 minutos).
 * - Las sesiones «Infantil» (`sorting-2`) son teatro para niños; el resto,
 *   microteatro.
 */
final class MicroteatroSource implements EventSource
{
    private const SITE    = 'https://microteatro.es/';
    private const TICKETS = 'https://taquilla.microteatro.es/madrid/programacion';
    private const NAME    = 'Microteatro por Dinero';
    private const LAT     = 40.4215369;
    private const LNG     = -3.7040616;
    private const ADDRESS = 'Calle de Loreto y Chicote, 9, 28004 Madrid';

    /** Días que se miran: el cron pide 30, con margen. */
    private const HORIZON_DAYS = 35;

    /** Lo que dura cada obra, para cerrar el día tras el último pase. */
    private const PLAY_MINUTES = 15;

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'microteatro';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $tz     = new \DateTimeZone('Europe/Madrid');
        $today  = new \DateTimeImmutable('today', $tz);
        $passes = [];
        $read   = 0;

        for ($i = 0; $i <= self::HORIZON_DAYS; ++$i) {
            $day  = $today->modify(sprintf('+%d days', $i));
            $html = $this->web->get(self::TICKETS . '?fecha=' . $day->format('d.m.Y'));
            if ($html === null) {
                continue;
            }
            ++$read;

            $xp = Html::xpath($html);
            foreach ($xp->query('//li[starts-with(@id, "obra_")]') as $li) {
                if (!$li instanceof \DOMElement) {
                    continue;
                }
                $id    = substr($li->getAttribute('id'), 5);
                $title = Html::clean(Html::attr($xp, './/a[@data-titulo]', 'data-titulo', $li), 200);
                $image = Html::attr($xp, './/div[' . Html::hasClass('ticket-image') . ']//img', 'src', $li);

                $times = [];
                foreach ($xp->query('.//ul[' . Html::hasClass('booking-list') . ']//label', $li) as $label) {
                    if (preg_match('/(\d{1,2}):(\d{2})/', $label->textContent, $t)) {
                        $times[] = $day->setTime((int) $t[1], (int) $t[2]);
                    }
                }
                if ($id === '' || $title === null || $image === null || $times === []) {
                    continue;
                }
                sort($times);

                $kids     = str_contains($li->getAttribute('class'), 'sorting-2');
                $synopsis = Html::text($xp, './/div[' . Html::hasClass('details-info') . ']//div[' . Html::hasClass('cell') . ']/p', $li);
                $genre    = Html::text($xp, './/ul[' . Html::hasClass('work-labels') . ']/li', $li);
                // Alguna sala se usa para otra cosa: una cata de cervezas no es una obra.
                $type = match (true) {
                    $kids                                     => ['events-kids', 'events-kids-theater'],
                    (bool) preg_match('/^cata\b/iu', $title) => ['events-experiences', 'events-experiences-gastronomy'],
                    default                                   => ['events-stage', 'events-stage-microtheater'],
                };

                $passes[] = new ScrapedEvent(
                    source: $this->name(),
                    externalId: $id,
                    title: $title,
                    start: $times[0],
                    end: end($times)->modify(sprintf('+%d minutes', self::PLAY_MINUTES)),
                    city: $this->city(),
                    venueName: self::NAME,
                    latitude: self::LAT,
                    longitude: self::LNG,
                    link: self::TICKETS . '/' . $id,
                    linkAction: 'buy',
                    description: Html::clean(trim(($genre !== null ? $genre . '. ' : '') . (string) $synopsis)),
                    imageUrl: Html::absolute($image, self::TICKETS),
                    detailUrl: self::TICKETS . '/' . $id,
                    venueAddress: self::ADDRESS,
                    subcategory: $type[0],
                    subtype: $type[1],
                );
            }
        }

        if ($read === 0) {
            throw new \RuntimeException('No se pudo descargar la programación de Microteatro');
        }

        return Shows::group($passes);
    }

    /** El listado del día ya lo trae todo. */
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
            categorySlug: 'culture-shows',
            latitude: self::LAT,
            longitude: self::LNG,
            address: self::ADDRESS,
            website: self::SITE,
        );
    }
}
