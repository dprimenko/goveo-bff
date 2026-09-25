<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Application\Shows;
use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\JsonLd;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Sala Villanos (salavillanos.es), la antigua Bogui Jazz: su ciclo «Villanos
 * del Jazz» es lo que era el Bogui. La agenda es una sola página con toda la
 * temporada —fecha, hora, título, cartel y etiquetas («Concierto», «Club»,
 * géneros)— y cada ficha lleva además un `schema.org/Event`, de donde salen el
 * cartel entero y la descripción.
 *
 * No se usa `JsonLdEventSource` porque tendría que abrir las ~100 fichas en cada
 * pasada, y la etiqueta «Club» —lo que separa una sesión de un concierto— sólo
 * está en el listado.
 */
final class SalaVillanosSource implements EventSource
{
    private const BASE = 'https://salavillanos.es';
    private const LAT  = 40.4040033;
    private const LNG  = -3.7000282;

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'villanos';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::BASE . '/agenda/');
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la agenda de Sala Villanos');
        }

        $xp     = Html::xpath($html);
        $passes = [];

        foreach ($xp->query('//*[' . Html::hasClass('event-card') . ']') as $card) {
            $href  = Html::attr($xp, './/a[contains(@href, "/evento/")]', 'href', $card);
            $title = Html::clean(Html::text($xp, './/h3', $card), 200);
            // «25 Sep» y «21:30H», en los dos primeros `h4`.
            $date  = Html::text($xp, '(.//h4)[1]', $card);
            $time  = Html::text($xp, '(.//h4)[2]', $card);
            if ($href === null || $title === null || $date === null || !preg_match('/^(\d{1,2})\s+(\p{L}+)/u', $date, $m)) {
                continue;
            }

            $month = SpanishDate::month($m[2]);
            $start = $month !== null ? SpanishDate::build((int) $m[1], $month, $time) : null;
            if ($start === null) {
                continue;
            }

            $tags = [];
            foreach ($xp->query('.//*[' . Html::hasClass('tag') . ']', $card) as $tag) {
                $tags[] = mb_strtolower(trim($tag->textContent));
            }
            $club = in_array('club', $tags, true);
            // Las entradas se venden en la ficha (widget de Notikumi); sin ese
            // botón en la tarjeta, la ficha sólo informa.
            $tickets = $xp->query('.//*[@data-notikumi]', $card)?->length > 0;

            $passes[] = new ScrapedEvent(
                source: $this->name(),
                // Las fechas de una gira en la sala son fichas distintas
                // (`…-2`, `…-3`) con el mismo título: un espectáculo por título
                // y mes, para que `Shows` las junte sin atar a la primera la
                // vuelta del mismo artista meses después.
                externalId: $this->slug($title) . ':' . $start->format('Y-m'),
                title: $title,
                start: $start,
                end: null,
                city: $this->city(),
                venueName: 'Sala Villanos',
                latitude: self::LAT,
                longitude: self::LNG,
                link: $href,
                linkAction: $tickets ? 'buy' : 'info',
                imageUrl: Html::attr($xp, './/img', 'src', $card),
                detailUrl: $href,
                venueAddress: 'Calle de Bernardino Obregón, 18, 28012 Madrid',
                subcategory: $club ? 'events-nightlife' : 'events-small-concerts',
                subtype: $club ? 'events-nightlife-dj-sessions' : null,
            );
        }

        return Shows::group($passes);
    }

    /**
     * El cartel del listado es una miniatura (711×400); el de la ficha, el
     * original. La descripción también está sólo en la ficha.
     */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        foreach (JsonLd::events($html) as $node) {
            return $event->withDetails(
                JsonLd::text($node['image'] ?? null),
                Html::clean(JsonLd::text($node['description'] ?? null, 'text')),
            );
        }

        return $event;
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: 'Sala Villanos',
            city: $this->city(),
            categorySlug: 'nightlife',
            latitude: self::LAT,
            longitude: self::LNG,
            address: 'Calle de Bernardino Obregón, 18, 28012 Madrid',
            website: self::BASE . '/',
        );
    }

    private function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return mb_substr(trim(preg_replace('/[^a-z0-9]+/', '-', $text) ?? '', '-'), 0, 150);
    }
}
