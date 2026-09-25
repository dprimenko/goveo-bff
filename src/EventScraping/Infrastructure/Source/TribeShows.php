<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Application\Shows;
use App\EventScraping\Domain\ScrapedEvent;

/**
 * Para las salas de `TribeEventsSource` que repiten la misma sesión cada semana
 * («ROBERTO TEMPO (Sala Lounge)» todos los viernes): The Events Calendar le da
 * un id a cada pase, así que la base los sacaría como eventos distintos. Esto
 * los junta por el nombre (ver `Shows`), sin tocar la base, que usan salas donde
 * cada evento sí es único (La Riviera, Teatros del Canal).
 *
 * El nombre es el título sin lo que va entre paréntesis detrás —la sala o el
 * estilo: «(Sala Lounge)», «(Afro Jazz)»—, que a veces cambia de un pase a otro.
 */
trait TribeShows
{
    /**
     * @param iterable<ScrapedEvent> $events
     *
     * @return list<ScrapedEvent>
     */
    private function groupShows(iterable $events): array
    {
        $shows = [];
        foreach ($events as $e) {
            // Sin tipo es lo que la sala no anuncia como plan (ver `classify`).
            if ($e->subcategory === null) {
                continue;
            }

            $shows[] = new ScrapedEvent(
                source: $e->source,
                externalId: $this->showSlug($e->title),
                title: $e->title,
                start: $e->start,
                end: $e->end,
                city: $e->city,
                venueName: $e->venueName,
                latitude: $e->latitude,
                longitude: $e->longitude,
                link: $e->link,
                linkAction: $e->linkAction,
                description: $e->description,
                imageUrl: $e->imageUrl,
                detailUrl: $e->detailUrl,
                weekdays: $e->weekdays,
                venueAddress: $e->venueAddress,
                subcategory: $e->subcategory,
                subtype: $e->subtype,
            );
        }

        return Shows::group($shows);
    }

    private function showSlug(string $title): string
    {
        $name = trim(explode('(', $title)[0]);
        $name = strtr(mb_strtolower($name === '' ? $title : $name), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return mb_substr(trim(preg_replace('/[^a-z0-9]+/', '-', $name) ?? '', '-'), 0, 200);
    }
}
