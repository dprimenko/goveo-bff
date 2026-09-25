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
 * Teatro Flamenco Madrid (teatroflamencomadrid.com), en la calle del Pez: es el
 * antiguo **Teatro Alfil**. teatroalfil.es ya sólo es una portada de 2 KB que
 * manda a Yllana o aquí, así que la cartelera de la sala es ésta.
 *
 * Dos cosas distintas:
 *
 * - **«Emociones»**, el espectáculo de todos los días (cuatro pases). La web no
 *   publica fechas, sólo «Todos los días del año»: sale como un evento con
 *   rango de `RANGE_DAYS` desde hoy.
 * - **Los ciclos** (`/ciclo/…`): especiales con fecha —Hispanidad, Zambomba,
 *   Círculo Flamenco—. Cada pase es un `carousel-item` con `data-item_date` y
 *   sus horas en el texto; los datos estructurados de la ficha sólo traen uno.
 */
final class TeatroFlamencoMadridSource implements EventSource
{
    private const HOME = 'https://teatroflamencomadrid.com/';

    /**
     * Hasta dónde se estira «Emociones». No tiene fin, y un rango de años
     * saldría en el feed como un evento que no acaba nunca; con esto caduca y
     * se revisa en el panel.
     */
    private const RANGE_DAYS = 90;

    /** Ciclos que no son un espectáculo sino una actividad (las clases). */
    private const SKIP_CYCLES = ['clases-de-flamenco'];

    private const VENUE = [
        'name'    => 'Teatro Flamenco Madrid',
        'lat'     => 40.4232189,
        'lng'     => -3.7044732,
        'address' => 'Calle del Pez, 10, 28004 Madrid',
    ];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'teatro-flamenco-madrid';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::HOME);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la portada de Teatro Flamenco Madrid');
        }

        $xp     = Html::xpath($html);
        $events = [];

        if (($daily = $this->daily($xp)) !== null) {
            $events[] = $daily;
        }

        $cycles = [];
        foreach ($xp->query('//a[contains(@href, "/ciclo/")]') as $a) {
            $slug = $a instanceof \DOMElement ? basename((string) parse_url($a->getAttribute('href'), \PHP_URL_PATH)) : '';
            if ($slug !== '' && !in_array($slug, self::SKIP_CYCLES, true)) {
                $cycles[$slug] = true;
            }
        }

        $passes = [];
        foreach (array_keys($cycles) as $slug) {
            array_push($passes, ...$this->cycle($slug));
        }

        return [...$events, ...Shows::group($passes)];
    }

    /** La portada y la ficha de cada ciclo ya lo traen todo. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
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
            website: self::HOME,
        );
    }

    /** «Emociones», si la portada sigue diciendo que es todos los días. */
    private function daily(\DOMXPath $xp): ?ScrapedEvent
    {
        $section = $xp->query('//section[@id="emociones"]')?->item(0);
        if ($section === null || !str_contains(mb_strtolower($section->textContent), 'todos los días')) {
            return null;
        }

        $title = Html::text($xp, './/h2', $section);
        // La imagen va en `<data-img>` para cargarla tarde.
        $img = Html::attr($xp, './/data-img', 'src', $section) ?? Html::attr($xp, './/img', 'src', $section);
        if ($title === null || $img === null) {
            return null;
        }

        $tz    = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $tz);
        // El primer pase del día es a las 17:00, todos los días.
        $first = preg_match('/(\d{1,2}):(\d{2})\s*h/u', (string) Html::text($xp, './/div[' . Html::hasClass('info') . ']', $section), $m)
            ? $today->setTime((int) $m[1], (int) $m[2])
            : $today;

        return $this->event(
            id: 'emociones',
            title: $title,
            start: $first,
            end: $today->modify(sprintf('+%d days', self::RANGE_DAYS))->setTime(23, 59),
            link: self::HOME,
            description: Html::clean(Html::text($xp, './p', $xp->query('.//div[h2]', $section)?->item(0))),
            image: Html::absolute($img, self::HOME),
        );
    }

    /**
     * Los pases de un ciclo. Cada `carousel-item` es un día (`data-item_date`)
     * y lleva las horas escritas («12 Oct 17:00, 19:00, 20:45»): un pase por hora.
     *
     * @return list<ScrapedEvent>
     */
    private function cycle(string $slug): array
    {
        $url  = self::HOME . 'ciclo/' . $slug;
        $html = $this->web->get($url);
        if ($html === null) {
            return [];
        }

        $xp          = Html::xpath($html);
        $tz          = new \DateTimeZone('Europe/Madrid');
        $description = Html::clean(Html::text($xp, '//div[' . Html::hasClass('body') . ']'));
        $passes      = [];

        foreach ($xp->query('//div[' . Html::hasClass('carousel-item') . ' and @data-item_date]') as $item) {
            if (!$item instanceof \DOMElement) {
                continue;
            }
            $day   = \DateTimeImmutable::createFromFormat('!Y-m-d', $item->getAttribute('data-item_date'), $tz);
            // El cartel del pase (el logo del ciclo también va en un `<picture>`).
            // `//img` y no `/img`: libxml no sabe que `<source>` va vacío y mete
            // el `<img>` dentro.
            $title = Html::attr($xp, './/picture[' . Html::hasClass('img') . ']//img', 'alt', $item);
            $img   = Html::attr($xp, './/picture[' . Html::hasClass('img') . ']//img', 'src', $item);
            if ($day === false || $title === null || $img === null) {
                continue;
            }

            preg_match_all('/(\d{1,2}):(\d{2})/', (string) Html::text($xp, './/div[' . Html::hasClass('fecha') . ']', $item), $times, \PREG_SET_ORDER);
            // Sin hora escrita, el pase dura el día.
            foreach ($times ?: [null] as $t) {
                $passes[] = $this->event(
                    id: 'ciclo-' . $slug,
                    title: $title,
                    start: $t === null ? $day : $day->setTime((int) $t[1], (int) $t[2]),
                    end: $t === null ? $day->setTime(23, 59) : null,
                    link: $url,
                    description: $description,
                    image: Html::absolute($img, self::HOME),
                );
            }
        }

        return $passes;
    }

    private function event(string $id, string $title, \DateTimeImmutable $start, ?\DateTimeImmutable $end, string $link, ?string $description, ?string $image): ScrapedEvent
    {
        return new ScrapedEvent(
            source: $this->name(),
            externalId: $id,
            title: $title,
            start: $start,
            end: $end,
            city: $this->city(),
            venueName: self::VENUE['name'],
            latitude: self::VENUE['lat'],
            longitude: self::VENUE['lng'],
            // Las entradas se compran en la misma página (el módulo de reserva).
            link: $link,
            linkAction: 'buy',
            description: $description,
            imageUrl: $image,
            detailUrl: $link,
            venueAddress: self::VENUE['address'],
            // Un teatro con espectáculo de flamenco, no un tablao de cena.
            subcategory: 'events-flamenco',
            subtype: 'events-flamenco-show',
        );
    }
}
