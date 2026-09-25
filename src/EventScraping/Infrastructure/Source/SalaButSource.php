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
 * Sala But (salabut.es), sala de conciertos de la calle Barceló: sólo su
 * agenda de conciertos (`/agenda-conciertos/`).
 *
 * La página está hecha a mano en Elementor, una tarjeta por concierto con la
 * foto del artista, el nombre, «16 OCTUBRE 2026» y el botón de compra (cada uno
 * en una ticketera distinta). **Sin hora**: cada concierto dura su día entero,
 * como en el Calderón.
 *
 * - Las fotos se ponen a 150 px; el original es el mismo nombre sin el tamaño
 *   (`-150x150`), que es lo que guarda WordPress.
 * - **Las sesiones de club no se leen** (`/agenda-sesiones/`): las de Mondo
 *   Disko ya entran por `mondo-disko`, y las demás (YASS, Loco Bongo) salen sin
 *   nombre —todas se titulan «Madrid»— y con el logo de la fiesta por cartel.
 *   Si en la de conciertos aparece algo de Mondo Disko, se salta igual.
 */
final class SalaButSource implements EventSource
{
    private const SITE    = 'https://www.salabut.es';
    private const LISTING = self::SITE . '/agenda-conciertos/';
    private const NAME    = 'Sala But';
    private const LAT     = 40.426975;
    private const LNG     = -3.699763;
    private const ADDRESS = 'Calle de Barceló, 11, 28004 Madrid';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'sala-but';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::LISTING);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la agenda de Sala But');
        }

        $xp     = Html::xpath($html);
        $events = [];

        // Cada tarjeta es un contenedor con una sola foto y sus títulos; los que
        // envuelven a varias tarjetas tienen más de una foto.
        $cards = $xp->query('//div[' . Html::hasClass('e-con') . '][count(.//img) = 1][.//*[' . Html::hasClass('elementor-heading-title') . ']]');
        foreach ($cards as $card) {
            $title = $start = null;
            foreach ($xp->query('.//*[' . Html::hasClass('elementor-heading-title') . ']', $card) as $heading) {
                $text = trim((string) preg_replace('/\s+/u', ' ', $heading->textContent));
                $date = $this->date($text);
                if ($date !== null) {
                    $start ??= $date;
                } elseif ($text !== '') {
                    $title ??= Html::clean($text, 200);
                }
            }

            $tickets = Html::attr($xp, './/a[' . Html::hasClass('elementor-button') . ']', 'href', $card);
            $image   = $this->image($xp, $card);
            if ($title === null || $start === null || $image === null || str_contains((string) $tickets, 'mondodisko')) {
                continue;
            }

            $events[] = new ScrapedEvent(
                source: $this->name(),
                externalId: $this->slug($title),
                title: $title,
                start: $start,
                end: $start->setTime(23, 59),
                city: $this->city(),
                venueName: self::NAME,
                latitude: self::LAT,
                longitude: self::LNG,
                link: $tickets ?? self::LISTING,
                linkAction: $tickets !== null ? 'buy' : 'info',
                imageUrl: $image,
                venueAddress: self::ADDRESS,
                subcategory: 'events-small-concerts',
            );
        }

        return Shows::group($events);
    }

    /** No hay fichas: todo lo que se sabe está en la tarjeta. */
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
            categorySlug: 'nightlife',
            latitude: self::LAT,
            longitude: self::LNG,
            address: self::ADDRESS,
            website: self::SITE . '/',
        );
    }

    /** «16 OCTUBRE 2026», con el año escrito. */
    private function date(string $text): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{1,2})\s+(\p{L}+)\s+(\d{4})$/u', $text, $m) || ($month = SpanishDate::month($m[2])) === null || !checkdate($month, (int) $m[1], (int) $m[3])) {
            return null;
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))
            ->setDate((int) $m[3], $month, (int) $m[1])
            ->setTime(0, 0);
    }

    /** La foto a tamaño original: la mayor del `srcset`, o la miniatura sin `-150x150`. */
    private function image(\DOMXPath $xp, \DOMNode $card): ?string
    {
        $src = Html::attr($xp, './/img', 'src', $card);
        if ($src === null || str_ends_with(strtolower($src), '.svg')) {
            return null;
        }

        $best  = null;
        $width = 0;
        foreach (explode(',', (string) Html::attr($xp, './/img', 'srcset', $card)) as $candidate) {
            if (preg_match('/^\s*(\S+)\s+(\d+)w\s*$/', $candidate, $m) && (int) $m[2] > $width) {
                $best  = $m[1];
                $width = (int) $m[2];
            }
        }

        return Html::absolute($best ?? (string) preg_replace('/-\d+x\d+(?=\.\w+$)/', '', $src), self::SITE);
    }

    private function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return mb_substr(trim(preg_replace('/[^a-z0-9]+/', '-', $text) ?? '', '-'), 0, 200);
    }
}
