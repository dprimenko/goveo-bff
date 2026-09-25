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
 * Teatro Real (teatroreal.es/es/calendario): toda la temporada en una página,
 * un bloque por día (`id="box09-2026-26"`, mes-año-día) con cada función, su
 * hora y la miniatura del espectáculo.
 *
 * Los datos estructurados de la ficha **no sirven para las fechas**: su
 * `startDate` es la de publicación de la página. Sí se usan para la
 * descripción.
 *
 * En el mismo calendario salen, sin hora ni imagen, los espectáculos que se
 * ponen a la venta ese día, y las charlas de «Actividades culturales» enlazadas
 * a un ancla de la ficha: ninguno es una función, y se descartan.
 */
final class TeatroRealSource implements EventSource
{
    private const LISTING = 'https://www.teatroreal.es/es/calendario';
    private const HOME    = 'https://www.teatroreal.es';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'teatro-real';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::LISTING);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar el calendario del Teatro Real');
        }

        $xp           = Html::xpath($html);
        $tz           = new \DateTimeZone('Europe/Madrid');
        $performances = [];

        foreach ($xp->query('//div[starts-with(@id, "box") and ' . Html::hasClass('item-box') . ']') as $day) {
            if (!$day instanceof \DOMElement || !preg_match('/^box(\d{2})-(\d{4})-(\d{2})$/', $day->getAttribute('id'), $d)) {
                continue;
            }
            $date = (new \DateTimeImmutable('now', $tz))->setDate((int) $d[2], (int) $d[1], (int) $d[3])->setTime(0, 0);

            foreach ($xp->query('.//div[' . Html::hasClass('contentbox') . ' and not(' . Html::hasClass('content-premiere') . ')]', $day) as $box) {
                $title = Html::clean(Html::text($xp, './/h3', $box), 200);
                $img   = Html::attr($xp, './/img', 'src', $box);
                $href  = Html::attr($xp, './/h3//a', 'href', $box);
                // Sin hora es un «a la venta»; con ancla, una charla de la ficha.
                if ($title === null || $img === null || $href === null || str_contains($href, '#')) {
                    continue;
                }

                $page     = (string) Html::absolute($href, self::HOME);
                $category = mb_strtolower((string) Html::text($xp, './/span', $box));
                [$subcategory, $subtype] = $this->classify(mb_strtolower($title), $category);

                // Varias funciones el mismo día (la matinal y la de la tarde),
                // un pase por hora.
                foreach ($xp->query('.//div[' . Html::hasClass('item-box--premiere__text--btn') . ']/a', $box) as $time) {
                    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($time->textContent), $t)) {
                        continue;
                    }

                    $performances[] = new ScrapedEvent(
                        source: $this->name(),
                        // El espectáculo, no la función: `Shows` junta sus pases.
                        externalId: basename((string) parse_url($page, \PHP_URL_PATH)),
                        title: $title,
                        start: $date->setTime((int) $t[1], (int) $t[2]),
                        end: null,
                        city: $this->city(),
                        venueName: 'Teatro Real',
                        latitude: 40.4181323,
                        longitude: -3.7102972,
                        link: $page,
                        linkAction: 'info',
                        imageUrl: $this->original($img),
                        detailUrl: $page,
                        venueAddress: 'Plaza de Isabel II, s/n, 28013 Madrid',
                        subcategory: $subcategory,
                        subtype: $subtype,
                    );
                }
            }
        }

        return Shows::group($performances);
    }

    /** El enlace de compra y la descripción, que sólo están en la ficha. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        $tickets     = Html::attr(Html::xpath($html), '//a[' . Html::hasClass('link-ticket') . ']', 'href');
        $description = null;
        foreach (JsonLd::events($html) as $node) {
            // El primer párrafo en rojo es un aviso de reparto («por motivos
            // de salud…»), no la presentación de la obra.
            $raw         = preg_replace('#<p class="text-red">.*?</p>#s', '', (string) JsonLd::text($node['description'] ?? null, 'text'));
            $description = Html::clean($raw, 400);
            break;
        }

        return $event->withDetails(
            null,
            $description,
            $tickets !== null && preg_match('#^https?://#', $tickets) ? $tickets : null,
            $tickets !== null ? 'buy' : null,
        );
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: 'Teatro Real',
            city: $this->city(),
            categorySlug: 'culture-business',
            latitude: 40.4181323,
            longitude: -3.7102972,
            address: 'Plaza de Isabel II, s/n, 28013 Madrid',
            website: 'https://www.teatroreal.es/es',
        );
    }

    /**
     * Ópera y conciertos de temporada se quedan en Escena sin subnivel; los
     * conciertos de «También en el Real» son de artistas de pop y canción.
     *
     * @return array{0: string, 1: ?string}
     */
    private function classify(string $title, string $category): array
    {
        return match (true) {
            str_contains($category, 'danza')    => ['events-stage', 'events-stage-dance'],
            str_contains($category, 'flamenco') => ['events-flamenco', 'events-flamenco-show'],
            preg_match('/\bcine/u', $title) === 1 => ['events-experiences', 'events-experiences-cinema'],
            preg_match('/\btaller/u', $title) === 1 => ['events-experiences', 'events-experiences-workshops'],
            str_contains($category, 'también en el real') => ['events-small-concerts', null],
            default => ['events-stage', null],
        };
    }

    /**
     * La miniatura del calendario es un recorte cuadrado de 348 px; quitando el
     * estilo de Drupal queda el original (la cabecera de la ficha).
     */
    private function original(string $src): string
    {
        $path = (string) preg_replace('#/styles/[^/]+/public/#', '/', (string) strtok($src, '?'));

        return (string) Html::absolute($path, self::HOME);
    }
}
