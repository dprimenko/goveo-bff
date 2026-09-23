<?php

declare(strict_types=1);

namespace App\EventScraping\Domain;

/**
 * Una sala tal como la conoce una fuente, para darla de alta como negocio si
 * todavía no está en Goveo.
 *
 * `website` es de donde se sacan el avatar, el escaparate, la descripción y el
 * teléfono (ver `WebsiteProfile`). Las salas del Ayuntamiento no tienen: salen
 * sólo con nombre, dirección y coordenadas, y el panel las completa.
 */
final class ScrapedVenue
{
    public function __construct(
        public readonly string $source,
        public readonly string $externalId,
        public readonly string $name,
        public readonly string $city,
        public readonly string $categorySlug,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly ?string $address = null,
        public readonly ?string $website = null,
        public readonly ?string $description = null,
        public readonly ?string $phone = null,
    ) {}
}
