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
 * Teatro de La Abadía (teatroabadia.com). La página de la temporada trae cada
 * espectáculo con su foto, su resumen, el rango («18 nov – 6 dic», sin año) y
 * el enlace a su venta en Koobin.
 *
 * Los días de función salen de **Koobin** (teatroabadia.koobin.com), no del
 * rango: la sala descansa los lunes y los ciclos cortos son de un día suelto.
 * Su página de un espectáculo trae en el `dataLayer` las funciones con fecha y
 * hora: todas si son pocas, y si son muchas sólo las del día elegido, con un
 * calendario que marca el resto (`<div d="19/11/2026" class="dia con">`).
 * Esos días se quedan sin hora, que para juntar los pases (`Shows`) sólo
 * cuenta la del primero.
 *
 * La API de WordPress tiene los espectáculos (`/wp/v2/espectaculos`), pero sin
 * fechas: las pone la plantilla.
 */
final class TeatroAbadiaSource implements EventSource
{
    private const HOME = 'https://www.teatroabadia.com';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'teatro-abadia';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $tz    = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $tz);
        // La temporada empieza en septiembre; en agosto ya está publicada la nueva.
        $year = (int) $today->format('Y') - ((int) $today->format('n') < 8 ? 1 : 0);
        $html = $this->web->get(sprintf('%s/temporada/%d-%d/', self::HOME, $year, $year + 1));
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la temporada del Teatro de La Abadía');
        }

        $xp           = Html::xpath($html);
        $performances = [];

        foreach ($xp->query('//article[' . Html::hasClass('espectaculos') . ']') as $card) {
            $title   = Html::clean(Html::text($xp, './/h2[' . Html::hasClass('entry-title') . ']', $card), 200);
            $page    = Html::attr($xp, './/h2[' . Html::hasClass('entry-title') . ']/a', 'href', $card);
            $image   = Html::attr($xp, './/div[' . Html::hasClass('post-image') . ']//img', 'src', $card);
            $range   = $this->range((string) Html::text($xp, './/div[' . Html::hasClass('fecha-rep') . ']', $card));
            $tickets = Html::attr($xp, './/a[' . Html::hasClass('btn-entradas') . ']', 'href', $card);
            if ($title === null || $page === null || $image === null || $range === null) {
                continue;
            }

            // Sólo se abre Koobin para lo que puede caer en las próximas
            // semanas: la temporada entera son treinta espectáculos.
            [$first, $last] = $range;
            if ($last < $today || $first > $today->modify('+60 days')) {
                continue;
            }

            $summary = (string) Html::text($xp, './/div[' . Html::hasClass('entry-summary') . ']', $card);
            $summary  = Html::clean((string) preg_replace('/\s*Leer más$/u', '', $summary));
            $flamenco = preg_match('/flamenc|cante\b|cantaor/iu', $title . ' ' . $summary . ' ' . $this->cycle($page)) === 1;

            $base = new ScrapedEvent(
                source: $this->name(),
                // El espectáculo, no la función: `Shows` junta sus pases.
                externalId: basename(trim((string) parse_url($page, \PHP_URL_PATH), '/')),
                title: $title,
                start: $first,
                end: $last->setTime(23, 59),
                city: $this->city(),
                venueName: 'Teatro de La Abadía',
                latitude: 40.4353022,
                longitude: -3.7094283,
                link: $tickets ?? $page,
                linkAction: $tickets !== null ? 'buy' : 'info',
                description: $summary,
                imageUrl: $image,
                detailUrl: $page,
                venueAddress: 'Calle de Fernández de los Ríos, 42, 28015 Madrid',
                subcategory: $flamenco ? 'events-flamenco' : 'events-stage',
                subtype: $flamenco ? 'events-flamenco-show' : 'events-stage-theater',
            );

            // Sin Koobin, el rango de la tarjeta: peor, pero mejor que nada.
            $sessions = $tickets !== null && str_contains($tickets, 'koobin.com') ? $this->sessions($tickets, $tz) : [];
            if ($sessions === []) {
                $performances[] = $base;
                continue;
            }
            foreach ($sessions as [$start, $end]) {
                $performances[] = $this->at($base, $start, $end);
            }
        }

        return Shows::group($performances);
    }

    /** La tarjeta ya lo trae todo. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: 'Teatro de La Abadía',
            city: $this->city(),
            categorySlug: 'culture-business',
            latitude: 40.4353022,
            longitude: -3.7094283,
            address: 'Calle de Fernández de los Ríos, 42, 28015 Madrid',
            website: self::HOME . '/',
        );
    }

    /**
     * «18 nov – 6 dic», «15 – 18 oct» o «14 oct»: el primer y el último día. El
     * año lo pone `SpanishDate`; sólo sirve para descartar lo lejano, las
     * fechas buenas son las de Koobin.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    private function range(string $text): ?array
    {
        if (!preg_match_all('/(\d{1,2})(?:\s+([a-zé]{3,}))?/iu', $text, $m, \PREG_SET_ORDER)) {
            return null;
        }
        $end   = end($m);
        $month = SpanishDate::month($end[2] ?? '');
        if ($month === null) {
            return null;
        }
        $last  = SpanishDate::build((int) $end[1], $month, null);
        $first = SpanishDate::build((int) $m[0][1], SpanishDate::month($m[0][2] ?? '') ?? $month, null);
        if ($first === null || $last === null) {
            return null;
        }

        // «26 dic – 3 ene»: el final es del año siguiente.
        return [$first, $last < $first ? $last->modify('+1 year') : $last];
    }

    /**
     * El ciclo de la ficha («Festival Suma Flamenca»): el resumen de la tarjeta
     * viene cortado y a veces no dice que es flamenco.
     */
    private function cycle(string $page): string
    {
        $html = $this->web->get($page);

        return $html === null ? '' : (string) Html::text(Html::xpath($html), '//dt[normalize-space() = "Ciclo"]/following-sibling::dd[1]');
    }

    /**
     * Las funciones según Koobin: con su hora las que la página trae en el
     * `dataLayer`, y el resto de días marcados en el calendario, enteros.
     *
     * @return list<array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable}>
     */
    private function sessions(string $url, \DateTimeZone $tz): array
    {
        $html = $this->web->get($url);
        if ($html === null) {
            return [];
        }

        // Las funciones que la página enseña con su hora: todas, si son pocas
        // (salen en lista); sólo la del día elegido, si hay calendario.
        $sessions = [];
        $timed    = [];
        preg_match_all("/'date':\\s*'(\\d{4}-\\d{2}-\\d{2}T[^']+)'/", $html, $m);
        foreach (array_unique($m[1]) as $raw) {
            try {
                $start = (new \DateTimeImmutable($raw))->setTimezone($tz);
            } catch (\Exception) {
                continue;
            }
            $sessions[]                     = [$start, null];
            $timed[$start->format('Y-m-d')] = true;
        }

        // Los demás días del calendario, sin hora.
        $xp = Html::xpath($html);
        foreach ($xp->query('//div[@d and (' . Html::hasClass('con') . ' or ' . Html::hasClass('seleccionado') . ')]') as $day) {
            $date = $day instanceof \DOMElement ? \DateTimeImmutable::createFromFormat('!d/m/Y', $day->getAttribute('d'), $tz) : false;
            if ($date !== false && !isset($timed[$date->format('Y-m-d')])) {
                $sessions[] = [$date, $date->setTime(23, 59)];
            }
        }

        return $sessions;
    }

    private function at(ScrapedEvent $e, \DateTimeImmutable $start, ?\DateTimeImmutable $end): ScrapedEvent
    {
        return new ScrapedEvent(
            source: $e->source,
            externalId: $e->externalId,
            title: $e->title,
            start: $start,
            end: $end,
            city: $e->city,
            venueName: $e->venueName,
            latitude: $e->latitude,
            longitude: $e->longitude,
            link: $e->link,
            linkAction: $e->linkAction,
            description: $e->description,
            imageUrl: $e->imageUrl,
            detailUrl: $e->detailUrl,
            venueAddress: $e->venueAddress,
            subcategory: $e->subcategory,
            subtype: $e->subtype,
        );
    }
}
