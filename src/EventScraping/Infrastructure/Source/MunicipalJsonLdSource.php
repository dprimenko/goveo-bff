<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;

/**
 * Base de las agendas culturales de un ayuntamiento del área de Madrid que
 * publican sus eventos como datos estructurados (Alcalá).
 *
 * Lo que añade a `JsonLdEventSource`:
 *
 * - **El municipio de verdad** en el evento y en la sala (como `FabrikSource`):
 *   la fuente es de la agenda de Madrid (`city()`), que es con lo que la lanza
 *   el cron, pero el negocio se busca en Alcalá, no en Madrid.
 * - **Muchas salas**, cada una con sus coordenadas fijas (`venues()`, sacadas una
 *   vez con la geocodificación de Google: los datos sólo traen la dirección).
 *   Una sala que no esté se descarta; una con categoría se da de alta como
 *   negocio, una sin ella (bibliotecas, plazas) va a la Agenda.
 */
abstract class MunicipalJsonLdSource extends JsonLdEventSource
{
    /** El municipio de los eventos y las salas («Alcalá de Henares»). */
    abstract protected function municipality(): string;

    /**
     * Salas por su nombre en los datos, ya normalizado (`key`): nombre con que
     * sale, dirección, lat, lng, categoría de negocio (nula = no se da de alta)
     * y web.
     *
     * @return array<string, array{0: string, 1: string, 2: float, 3: float, 4: ?string, 5: ?string}>
     */
    abstract protected function venues(): array;

    public function fetch(): iterable
    {
        foreach (parent::fetch() as $e) {
            // «“MAMÁ”»: las comillas de todo el título sobran en una tarjeta.
            $title = (string) preg_replace('/^[“"«]\s*([^“”"«»]+?)\s*[”"»]\.?$/u', '$1', $e->title);
            yield $this->rebuild($e, city: $this->municipality(), title: $title);
        }
    }

    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        foreach ($this->venues() as $key => [$name, $address, $lat, $lng, $category, $website]) {
            if ($name === $event->venueName && $category !== null) {
                return new ScrapedVenue(
                    source: $this->name(),
                    externalId: 'venue-' . $this->slug($name),
                    name: $name,
                    city: $this->municipality(),
                    categorySlug: $category,
                    latitude: $lat,
                    longitude: $lng,
                    address: $address,
                    website: $website,
                );
            }
        }

        return null;
    }

    protected function venue(array $node): ?array
    {
        $location = $node['location'] ?? null;
        if (is_array($location) && array_is_list($location)) {
            $location = $location[0] ?? null;
        }
        $raw   = is_array($location) ? (string) ($location['name'] ?? '') : '';
        $venue = $this->venues()[$this->key(html_entity_decode($raw, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'))] ?? null;
        if ($venue === null) {
            return null;
        }
        [$name, $address, $lat, $lng, $category, $website] = $venue;

        return [
            'name'     => $name,
            'lat'      => $lat,
            'lng'      => $lng,
            'address'  => $address,
            'website'  => (string) $website,
            'category' => (string) $category,
        ];
    }

    protected function key(string $name): string
    {
        return $this->slug($name);
    }

    /**
     * Un evento con algo cambiado: `ScrapedEvent` es inmutable y `withDetails`
     * sólo toca imagen, texto y enlace.
     */
    protected function rebuild(ScrapedEvent $e, mixed ...$changes): ScrapedEvent
    {
        return new ScrapedEvent(...array_merge([
            'source'      => $e->source,
            'externalId'  => $e->externalId,
            'title'       => $e->title,
            'start'       => $e->start,
            'end'         => $e->end,
            'city'        => $e->city,
            'venueName'   => $e->venueName,
            'latitude'    => $e->latitude,
            'longitude'   => $e->longitude,
            'link'        => $e->link,
            'linkAction'  => $e->linkAction,
            'description' => $e->description,
            'imageUrl'    => $e->imageUrl,
            'detailUrl'   => $e->detailUrl,
            'weekdays'    => $e->weekdays,
            'venueAddress' => $e->venueAddress,
            'subcategory' => $e->subcategory,
            'subtype'     => $e->subtype,
        ], $changes));
    }

    /**
     * Tipo para lo que es de niños, o nulo: «infantil», «familiar», una edad
     * recomendada baja, títeres, cuentacuentos.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function kids(string $text): ?array
    {
        $text = mb_strtolower($text);
        if (!preg_match('/infantil|familiar|en familia|beb[eé]s|ni[nñ]os|peques|t[ií]teres|marionetas|cuentacuentos|a partir de [1-9] a[nñ]os|de [0-9] a 1?[0-9] a[nñ]os/u', $text)) {
            return null;
        }

        return match (true) {
            (bool) preg_match('/cuentacuentos|cuentos?\b/u', $text)                 => ['events-kids', 'events-kids-storytelling'],
            (bool) preg_match('/\btaller/u', $text)                                 => ['events-kids', 'events-kids-workshops'],
            (bool) preg_match('/teatro|t[ií]teres|marionetas|circo|magia|musical/u', $text) => ['events-kids', 'events-kids-theater'],
            default                                                                  => ['events-kids', 'events-kids-family-plans'],
        };
    }
}
