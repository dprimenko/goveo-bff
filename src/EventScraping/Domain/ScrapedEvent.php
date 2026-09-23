<?php

declare(strict_types=1);

namespace App\EventScraping\Domain;

/**
 * Un evento tal como lo cuenta una web de fuera, antes de ser nada nuestro.
 *
 * `imageUrl` y `description` pueden llegar vacíos del listado: muchas webs sólo
 * los ponen en la ficha del evento, y abrir cientos de fichas para luego
 * descartar la mitad por la fecha es trabajo tirado. Se piden después de
 * filtrar (ver `EventSource::enrich()`).
 */
final class ScrapedEvent
{
    /**
     * @param list<int>|null $weekdays Días en que se repite (ISO: 1 lunes … 7
     *                                 domingo), si la fuente lo dice. Nulo es
     *                                 «todos los días del rango».
     */
    public function __construct(
        public readonly string $source,
        public readonly string $externalId,
        public readonly string $title,
        public readonly \DateTimeImmutable $start,
        public readonly ?\DateTimeImmutable $end,
        public readonly string $city,
        public readonly string $venueName,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly ?string $link,
        public readonly string $linkAction = 'info',
        public readonly ?string $description = null,
        public readonly ?string $imageUrl = null,
        public readonly ?string $detailUrl = null,
        public readonly ?array $weekdays = null,
        /** Calle y número, cuando la fuente los da (el Ayuntamiento). */
        public readonly ?string $venueAddress = null,
    ) {}

    public function withDetails(?string $imageUrl, ?string $description, ?string $link = null, ?string $linkAction = null): self
    {
        return new self(
            source: $this->source,
            externalId: $this->externalId,
            title: $this->title,
            start: $this->start,
            end: $this->end,
            city: $this->city,
            venueName: $this->venueName,
            latitude: $this->latitude,
            longitude: $this->longitude,
            link: $link ?? $this->link,
            linkAction: $linkAction ?? $this->linkAction,
            description: $description ?? $this->description,
            imageUrl: $imageUrl ?? $this->imageUrl,
            detailUrl: $this->detailUrl,
            weekdays: $this->weekdays,
            venueAddress: $this->venueAddress,
        );
    }
}
