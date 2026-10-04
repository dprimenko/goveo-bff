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
 * Zoo Aquarium de Madrid (zoomadrid.com), sólo sus **talleres en familia**: el
 * zoo es una atracción de todos los días y eso no es un evento, pero algunos
 * sábados y festivos organiza jornadas con fecha («Veterinario por un día»,
 * «Halloween en familia»).
 *
 * Están en una página, cada taller con su foto, sus fechas en el titular y el
 * nombre en negrita debajo. Las fechas van escritas a mano y de muchas formas
 * («26/09, 10/10, 21/11 y 08/12», «24, 25 y 31/10 y 01/11», «14 y 28 de
 * noviembre»): ver `dates()`. Sin año: lo deduce `SpanishDate`. La hora es la
 * del apartado «Horarios» de la página, la misma para todos.
 *
 * Cada taller es un evento con todas sus fechas (`Shows`). Se reserva por
 * teléfono: el enlace es la página.
 */
final class ZooMadridSource implements EventSource
{
    private const SITE = 'https://www.zoomadrid.com';
    private const PAGE = self::SITE . '/educacion/campamentos/talleres-en-familia';

    private const NAME    = 'Zoo Aquarium de Madrid';
    private const LAT     = 40.4089556;
    private const LNG     = -3.7612288;
    private const ADDRESS = 'Casa de Campo, s/n, 28011 Madrid';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'zoo-madrid';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::PAGE);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar los talleres en familia del Zoo');
        }

        $xp = Html::xpath($html);
        // «De 11:00 a 14:00 horas.», debajo de «HORARIOS».
        $hours = preg_match('/HORARIOS\s*De\s*(\d{1,2})[:.](\d{2})\s*a\s*(\d{1,2})[:.](\d{2})/u', (string) Html::text($xp, '//body'), $h)
            ? [(int) $h[1], (int) $h[2], (int) $h[3], (int) $h[4]]
            : null;

        $passes = [];
        foreach ($xp->query('//div[' . Html::hasClass('ca04_textrich') . '][preceding-sibling::div[' . Html::hasClass('ca02_title') . ']]') as $block) {
            $title = Html::clean(Html::text($xp, './/p[1]//b | .//p[1]//strong', $block), 200);
            $when  = Html::text($xp, 'preceding-sibling::div[' . Html::hasClass('ca02_title') . '][1]//h2', $block);
            $src   = Html::attr($xp, 'preceding-sibling::div[' . Html::hasClass('ca03_image') . '][1]//img', 'src', $block);
            if ($title === null || $when === null || $src === null) {
                continue;
            }

            $text        = Html::clean($block->textContent, 600) ?? '';
            $description = Html::clean(trim(mb_substr($text, mb_strlen($title))), 400);

            foreach ($this->dates($when) as $day) {
                $passes[] = new ScrapedEvent(
                    source: $this->name(),
                    externalId: $this->slug($title),
                    title: $title,
                    start: $hours !== null ? $day->setTime($hours[0], $hours[1]) : $day,
                    end: $hours !== null ? $day->setTime($hours[2], $hours[3]) : $day->setTime(23, 59),
                    city: $this->city(),
                    venueName: self::NAME,
                    latitude: self::LAT,
                    longitude: self::LNG,
                    link: self::PAGE,
                    description: $description,
                    imageUrl: Html::absolute($src, self::SITE),
                    detailUrl: self::PAGE,
                    venueAddress: self::ADDRESS,
                    subcategory: 'events-kids',
                    subtype: 'events-kids-workshops',
                );
            }
        }

        return Shows::group($passes);
    }

    /** La página ya lo trae todo. */
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
            website: self::SITE . '/',
        );
    }

    /**
     * «24, 25 y 31/10 y 01/11» o «14 y 28 de noviembre»: los días se van
     * apuntando y el mes que llega después (`/10`, «de noviembre») es el de
     * todos los que esperaban.
     *
     * @return list<\DateTimeImmutable>
     */
    private function dates(string $text): array
    {
        preg_match_all('/(\d{1,2})(?:\/(\d{1,2}))?|de\s+([a-záéíóú]+)/iu', $text, $tokens, \PREG_SET_ORDER);

        $pending = [];
        $out     = [];
        foreach ($tokens as $t) {
            $month = match (true) {
                ($t[3] ?? '') !== ''  => SpanishDate::month($t[3]),
                ($t[2] ?? '') !== ''  => (int) $t[2],
                default               => null,
            };
            if (($t[1] ?? '') !== '') {
                $pending[] = (int) $t[1];
            }
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

    private function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return mb_substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-'), 0, 200);
    }
}
