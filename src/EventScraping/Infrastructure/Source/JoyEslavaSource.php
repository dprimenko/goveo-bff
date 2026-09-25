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
 * Joy Eslava (teatroeslava.com): conciertos y las sesiones de discoteca.
 *
 * Es un WordPress con dos tipos de entrada propios, `conciertos` y `club`, y su
 * API los lista sin tener que leer la maqueta. Pero **la fecha, la hora y el
 * cartel no están en la API** (son campos de JetEngine que no expone), así que
 * se abre la ficha de cada uno — son una quincena.
 *
 * Ojo con la imagen para compartir: en los conciertos es **la misma para todos**
 * (un cartel viejo de un homenaje a Dire Straits). El cartel bueno es la imagen
 * destacada de la ficha.
 *
 * La página «Club» de la web tira de Fourvenues, que pide una clave de su API y
 * responde 403 a quien no es navegador; las fichas `club` de WordPress sí se
 * leen, aunque sólo publican la semana en curso.
 */
final class JoyEslavaSource implements EventSource
{
    private const SITE = 'https://teatroeslava.com';

    /** Tipo de entrada de WordPress → tipo de evento y subnivel. */
    private const TYPES = [
        'conciertos' => ['events-small-concerts', null],
        'club'       => ['events-nightlife', 'events-nightlife-clubs'],
    ];

    /** Donde se venden las entradas; el resto de enlaces de la ficha son de la casa. */
    private const TICKETS = '#fourvenues\.com|feverup\.com|entradas\.com/(?!city/)|ticketmaster\.|dice\.fm|wegow\.com|seetickets\.|livenation\.|eventbrite\.|entradium\.com|bclever\.#i';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'joy-eslava';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $performances = [];

        foreach (self::TYPES as $type => [$subcategory, $subtype]) {
            $raw   = $this->web->get(self::SITE . '/wp-json/wp/v2/' . $type . '?per_page=50&_fields=link,slug,title,yoast_head_json.og_description');
            $posts = $raw !== null ? json_decode($raw, true) : null;
            if (!is_array($posts)) {
                if ($type === 'conciertos') {
                    throw new \RuntimeException('No se pudo leer la agenda de Joy Eslava');
                }
                continue;
            }

            foreach ($posts as $post) {
                $link  = is_string($post['link'] ?? null) ? $post['link'] : null;
                // «Macaco en Madrid», «EnJoy Friday en Joy Eslava»: la coletilla
                // es para buscadores y en la tarjeta ya se ve la sala.
                $title = Html::clean($post['title']['rendered'] ?? null, 200);
                $title = $title === null ? null : preg_replace('/\s+en (Madrid|Joy Eslava|Teatro Eslava)$/iu', '', $title);
                if ($link === null || $title === null || ($html = $this->web->get($link)) === null) {
                    continue;
                }

                $event = $this->fromDetail($html, $link, $title, $type === 'club', $subcategory, $subtype);
                if ($event === null) {
                    continue;
                }

                // En las fichas sin texto propio, la descripción es el título otra vez.
                $description    = Html::clean($post['yoast_head_json']['og_description'] ?? null);
                $performances[] = $event->withDetails(null, $description !== null && mb_strlen($description) > 60 ? $description : null);
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
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: 'Joy Eslava',
            city: $this->city(),
            categorySlug: 'nightlife',
            latitude: 40.4171637,
            longitude: -3.7065661,
            address: 'Calle del Arenal, 11, 28013 Madrid',
            website: self::SITE . '/',
        );
    }

    private function fromDetail(string $html, string $link, string $title, bool $club, ?string $subcategory, ?string $subtype): ?ScrapedEvent
    {
        $xp = Html::xpath($html);

        // Fecha y hora son campos sueltos de la ficha, cada uno en su caja:
        // «miércoles, 21 de octubre 2026» o «viernes 25.09.2026», y «20:30h».
        $date = $time = null;
        foreach ($xp->query('//*[' . Html::hasClass('jet-listing-dynamic-field__content') . ']') as $field) {
            $text = trim($field->textContent);
            $date ??= $this->date($text);
            if ($time === null && preg_match('/^(\d{1,2}):(\d{2})\s*h$/i', $text, $m)) {
                $time = [(int) $m[1], (int) $m[2]];
            }
        }
        // El cartel es la imagen destacada; el logo de la cabecera lleva las
        // mismas clases, pero es SVG.
        $image = Html::attr($xp, '//img[contains(@class, "wp-image-") and (' . Html::hasClass('attachment-large') . ' or ' . Html::hasClass('attachment-full') . ') and not(contains(@src, ".svg"))]', 'src');
        if ($date === null || $image === null) {
            return null;
        }

        [$hour, $minute] = $time ?? [0, 0];
        // La sesión de club «del viernes a las 00:00h» es la noche del viernes,
        // no la madrugada del jueves: se deja en el día que anuncian, como hace
        // `SpanishDate` con las «23:59» de las salas.
        if ($club && $hour < 7) {
            [$hour, $minute] = [23, 59];
        }
        $start = $date->setTime($hour, $minute);

        $tickets = null;
        foreach ($xp->query('//a[@href][not(ancestor::footer) and not(ancestor::header)]') as $a) {
            $href = $a instanceof \DOMElement ? $a->getAttribute('href') : '';
            if (preg_match(self::TICKETS, $href)) {
                $tickets = $href;
                break;
            }
        }

        $slug = trim((string) parse_url($link, \PHP_URL_PATH), '/');

        return new ScrapedEvent(
            source: $this->name(),
            // Las sesiones de club son una entrada por semana con el mismo
            // nombre («EnJoy Friday»): la sesión es el nombre, no la entrada,
            // para que la de la semana que viene no sea otro evento.
            externalId: $club ? 'club-' . $this->slug($title) : basename($slug),
            title: $title,
            start: $start,
            end: $time === null && !$club ? $start->setTime(23, 59) : null,
            city: $this->city(),
            venueName: 'Joy Eslava',
            latitude: 40.4171637,
            longitude: -3.7065661,
            link: $tickets ?? $link,
            linkAction: $tickets !== null ? 'buy' : 'info',
            imageUrl: Html::absolute($image, self::SITE),
            detailUrl: $link,
            venueAddress: 'Calle del Arenal, 11, 28013 Madrid',
            subcategory: $subcategory,
            subtype: $subtype,
        );
    }

    private function date(string $text): ?\DateTimeImmutable
    {
        $tz = new \DateTimeZone('Europe/Madrid');

        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $text, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/(\d{1,2}) de ([a-záéíóú]+),? (?:de )?(\d{4})/iu', $text, $m) && ($month = SpanishDate::month($m[2])) !== null) {
            [$day, $year] = [(int) $m[1], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($month, $day, $year)
            ? (new \DateTimeImmutable('now', $tz))->setDate($year, $month, $day)->setTime(0, 0)
            : null;
    }

    private function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim(preg_replace('/[^a-z0-9]+/', '-', $text) ?? '', '-');
    }
}
