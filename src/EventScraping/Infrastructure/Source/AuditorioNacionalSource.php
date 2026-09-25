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
 * Auditorio Nacional de Música (auditorionacional.inaem.gob.es), un Plone.
 *
 * `/es/programacion` es la lista de funciones por orden de fecha, doce por
 * página (`?b_start:int=12`…; no hace caso de `b_size`): cada una con su sala
 * y la fecha en ISO, con zona. Se pasan páginas hasta salir del horizonte, no
 * la temporada entera (~33 páginas).
 *
 * Las funciones de un mismo concierto (la OCNE toca viernes, sábado y domingo)
 * enlazan a la misma ficha: `Shows` las junta.
 *
 * Es música clásica —orquestas, cámara, algo de ópera en concierto—, así que va
 * a Escena sin subnivel, como la ópera del Teatro Real. Lo flamenco va a
 * Flamenco/Espectáculo.
 */
final class AuditorioNacionalSource implements EventSource
{
    private const HOME = 'https://auditorionacional.inaem.gob.es';
    private const LIST = self::HOME . '/es/programacion?b_start:int=%d';

    private const PER_PAGE  = 12;
    private const MAX_PAGES = 12;

    /** Hasta dónde se leen páginas: algo más que la ventana del comando. */
    private const HORIZON = '+45 days';

    private const VENUE = [
        'name'    => 'Auditorio Nacional de Música',
        'lat'     => 40.4460179,
        'lng'     => -3.677671,
        'address' => 'Calle del Príncipe de Vergara, 146, 28002 Madrid',
    ];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'auditorio-nacional';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $tz           = new \DateTimeZone('Europe/Madrid');
        $horizon      = new \DateTimeImmutable(self::HORIZON, $tz);
        $performances = [];

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $html = $this->web->get(sprintf(self::LIST, $page * self::PER_PAGE));
            if ($html === null) {
                if ($page === 0) {
                    throw new \RuntimeException('No se pudo descargar la programación del Auditorio Nacional');
                }
                break;
            }

            $xp    = Html::xpath($html);
            $cards = $xp->query('//article[' . Html::hasClass('eventitem') . ']');
            if ($cards->length === 0) {
                break;
            }

            $last = null;
            foreach ($cards as $card) {
                $title = Html::clean(Html::text($xp, './/h3[' . Html::hasClass('eventitem__title') . ']', $card), 200);
                $url   = Html::attr($xp, './/h3//a', 'href', $card);
                $iso   = Html::text($xp, './/span[' . Html::hasClass('hour') . ']', $card);
                $start = $iso !== null ? \DateTimeImmutable::createFromFormat(\DATE_ATOM, $iso) : false;
                if ($title === null || $url === null || $start === false) {
                    continue;
                }

                $start = $start->setTimezone($tz);
                $last  = $start;
                $room  = Html::text($xp, './/p[' . Html::hasClass('location') . ']', $card);
                [$subcategory, $subtype] = $this->classify(mb_strtolower($title));

                $performances[] = new ScrapedEvent(
                    source: $this->name(),
                    // La ficha es del concierto, no de la función.
                    externalId: basename((string) parse_url($url, \PHP_URL_PATH)),
                    title: $title,
                    start: $start,
                    end: null,
                    city: $this->city(),
                    venueName: self::VENUE['name'],
                    latitude: self::VENUE['lat'],
                    longitude: self::VENUE['lng'],
                    link: $url,
                    linkAction: 'info',
                    description: $room,
                    detailUrl: $url,
                    venueAddress: self::VENUE['address'],
                    subcategory: $subcategory,
                    subtype: $subtype,
                );
            }

            if ($last === null || $last > $horizon) {
                break;
            }
        }

        return Shows::group($performances);
    }

    /**
     * Cartel, programa y compra, de la ficha del concierto. La lista trae el
     * cartel en miniatura circular; la ficha, en grande (`@@images/image/large`).
     */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        $xp      = Html::xpath($html);
        $poster  = Html::attr($xp, '//div[' . Html::hasClass('content') . ']//img', 'src');
        $tickets = Html::attr($xp, '//a[' . Html::hasClass('buy') . ']', 'href');
        $tickets = $tickets !== null && preg_match('#^https?://#', $tickets) ? $tickets : null;

        // No hay sinopsis: el texto de la ficha es el programa —intérpretes y
        // obras—, que es lo que se busca en un concierto.
        $lines = [];
        foreach ($xp->query('//div[' . Html::hasClass('content') . ']//*[self::h4 or self::p]') as $node) {
            $text = Html::clean($node->textContent, 400);
            // Los encabezados vacíos llevan un `&nbsp;`, que no cuenta como espacio.
            if ($text !== null && trim($text, " \u{00A0}") !== '') {
                $lines[] = $text;
            }
        }

        return $event->withDetails(
            $poster !== null ? Html::absolute($poster, self::HOME) : null,
            $lines !== [] ? Html::clean(implode(' · ', $lines), 400) : null,
            $tickets,
            $tickets !== null ? 'buy' : null,
        );
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: self::VENUE['name'],
            city: $this->city(),
            categorySlug: 'culture-business',
            latitude: self::VENUE['lat'],
            longitude: self::VENUE['lng'],
            address: self::VENUE['address'],
            website: self::HOME . '/es',
        );
    }

    /** @return array{0: string, 1: ?string} */
    private function classify(string $title): array
    {
        return match (true) {
            str_contains($title, 'flamenc') => ['events-flamenco', 'events-flamenco-show'],
            default                         => ['events-stage', null],
        };
    }
}
