<?php

declare(strict_types=1);

namespace App\EventScraping\Domain;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Una web de la que se importan eventos.
 *
 * Añadir una sala es añadir una clase que implemente esto: se registra sola por
 * la etiqueta y el comando la recorre con las demás.
 */
#[AutoconfigureTag('goveo.event_source')]
interface EventSource
{
    /** Identificador corto y estable: va dentro de `external_ref`, no cambiarlo. */
    public function name(): string;

    /**
     * La agenda a la que pertenece la fuente («Madrid»): es con lo que la lanza
     * el cron (`--city`). La ciudad **de cada evento** va en el propio evento
     * (`ScrapedEvent::city`), y es la que se usa para buscar su negocio: una sala
     * del área —Fabrik en Humanes, una terraza en Alcorcón— es de la agenda de
     * Madrid pero no de su municipio.
     */
    public function city(): string;

    /** @return iterable<ScrapedEvent> Lo que publica el listado, sin filtrar. */
    public function fetch(): iterable;

    /**
     * Completa lo que el listado no trae —imagen, descripción— abriendo la ficha
     * del evento. Se llama sólo con lo que ya pasó el filtro de fechas.
     */
    public function enrich(ScrapedEvent $event): ScrapedEvent;

    /**
     * La sala del evento, para darla de alta como negocio si no está en Goveo.
     * Nulo si esta fuente no sabe de ella lo bastante como para crear una ficha
     * —entonces el evento va a la Agenda de su ciudad—.
     */
    public function venueFor(ScrapedEvent $event): ?ScrapedVenue;
}
