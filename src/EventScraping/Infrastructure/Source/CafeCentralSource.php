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
 * Café Central (cafecentralmadrid.com), jazz todos los días. Web estática
 * (Hugo): `/programacion/` trae todos los conciertos en una página, y cada
 * tarjeta lleva sus fechas en atributos (`data-event-date`, `data-end-date`),
 * que es lo que usa la propia web para filtrar — más fiable que el texto
 * («miércoles 30 sept. - jueves 1 oct.»).
 *
 * Tiene **dos salas**: el Café Central Ateneo, el club de siempre, y La
 * Cátedra, el auditorio del Ateneo. Cada una sale como su propio sitio.
 *
 * Un grupo toca normalmente dos o tres noches seguidas, con pases a las 20 y a
 * las 22: sale un evento por grupo con su rango, desde el primer pase (ver
 * `Shows`).
 */
final class CafeCentralSource implements EventSource
{
    private const SITE    = 'https://cafecentralmadrid.com';
    private const LISTING = self::SITE . '/programacion/';

    /** Las dos salas, por la palabra con que la web las distingue. */
    private const VENUES = [
        'catedra' => [
            'name'    => 'Café Central La Cátedra',
            'lat'     => 40.4152211,
            'lng'     => -3.6982251,
            'address' => 'Calle del Prado, 21, 28014 Madrid',
        ],
        'ateneo' => [
            'name'    => 'Café Central',
            'lat'     => 40.4154432,
            'lng'     => -3.6979046,
            'address' => 'Calle de Santa Catalina, 10, 28014 Madrid',
        ],
    ];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'cafe-central';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::LISTING);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la programación de Café Central');
        }

        $tz           = new \DateTimeZone('Europe/Madrid');
        $xp           = Html::xpath($html);
        $performances = [];

        foreach ($xp->query('//div[' . Html::hasClass('event-item') . ']') as $card) {
            if (!$card instanceof \DOMElement) {
                continue;
            }

            $title  = Html::text($xp, './/h2', $card);
            $image  = Html::attr($xp, './/img', 'src', $card);
            $detail = Html::attr($xp, './/a[.//h2]', 'href', $card);
            $first  = \DateTimeImmutable::createFromFormat('!Y-m-d', $card->getAttribute('data-event-date'), $tz) ?: null;
            $last   = \DateTimeImmutable::createFromFormat('!Y-m-d', $card->getAttribute('data-end-date'), $tz) ?: $first;
            if ($title === null || $image === null || $detail === null || $first === null || $last < $first) {
                continue;
            }

            // Los datos de la derecha son tres líneas: días, pases y sala.
            $lines = [];
            foreach ($xp->query('.//span', $card) as $span) {
                $lines[] = trim($span->textContent);
            }
            $time  = $this->firstTime(implode(' ', $lines));
            $venue = self::VENUES[str_contains(mb_strtolower(implode(' ', $lines)), 'cátedra') ? 'catedra' : 'ateneo'];

            $tickets = Html::absolute(Html::attr($xp, './/a[contains(@href, "/reservas/")]', 'href', $card), self::SITE);
            $text    = $xp->query('.//div[contains(@class, "line-clamp")]', $card)?->item(0);
            // La formación va línea a línea con `<br>`: sin separarla, los
            // nombres salen pegados («vozDavid Sanz»).
            $description = $text !== null
                ? Html::clean(preg_replace(['#<br\s*/?>#i', '#</p>#i'], [', ', '</p> '], (string) $text->ownerDocument?->saveHTML($text)))
                : null;

            // Un pase por noche, todos con el mismo id: `Shows` los junta.
            for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
                $start = $time !== null ? $day->setTime($time[0], $time[1]) : $day;

                $performances[] = new ScrapedEvent(
                    source: $this->name(),
                    externalId: basename(trim((string) parse_url($detail, \PHP_URL_PATH), '/')),
                    title: $title,
                    start: $start,
                    end: $time === null ? $day->setTime(23, 59) : null,
                    city: $this->city(),
                    venueName: $venue['name'],
                    latitude: $venue['lat'],
                    longitude: $venue['lng'],
                    link: $tickets ?? $detail,
                    linkAction: $tickets !== null ? 'buy' : 'info',
                    description: $description,
                    imageUrl: Html::absolute($image, self::SITE),
                    detailUrl: Html::absolute($detail, self::SITE),
                    venueAddress: $venue['address'],
                    subcategory: 'events-small-concerts',
                );
            }
        }

        return Shows::group($performances);
    }

    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        $venue = $event->venueName === self::VENUES['catedra']['name'] ? self::VENUES['catedra'] : self::VENUES['ateneo'];

        return new ScrapedVenue(
            source: $this->name(),
            externalId: $venue === self::VENUES['catedra'] ? 'venue-catedra' : 'venue',
            name: $venue['name'],
            city: $this->city(),
            categorySlug: 'nightlife',
            latitude: $venue['lat'],
            longitude: $venue['lng'],
            address: $venue['address'],
            website: self::SITE . '/',
        );
    }

    /**
     * El primer pase: «8PM & 10PM», «7:30 PM».
     *
     * @return array{0: int, 1: int}|null
     */
    private function firstTime(string $text): ?array
    {
        if (!preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*(AM|PM)\b/i', $text, $m)) {
            return null;
        }
        $hour = (int) $m[1] % 12 + (strtoupper($m[3]) === 'PM' ? 12 : 0);

        return [$hour, (int) ($m[2] ?: 0)];
    }
}
