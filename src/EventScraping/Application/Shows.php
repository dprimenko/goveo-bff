<?php

declare(strict_types=1);

namespace App\EventScraping\Application;

use App\EventScraping\Domain\ScrapedEvent;

/**
 * Junta los pases de un mismo espectáculo en **un solo evento con su rango**:
 * «SIX el Musical» una vez, del primer pase al último día, y no un evento por
 * función —un musical en cartel dos meses eran ~25 casi iguales en la cola y
 * en el feed—. Lo pidió así el socio al verlo.
 *
 * Sólo cuenta lo que no ha pasado: el rango empieza en el próximo pase. Si un
 * espectáculo tiene un único pase, se queda como estaba.
 */
final class Shows
{
    /**
     * @param iterable<ScrapedEvent> $performances pases, con `externalId` del espectáculo
     *                                             (el mismo en todos sus pases)
     *
     * @return list<ScrapedEvent>
     */
    public static function group(iterable $performances): array
    {
        $now    = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid'));
        $groups = [];

        foreach ($performances as $p) {
            if (($p->end ?? $p->start) < $now) {
                continue;
            }
            $groups[$p->externalId][] = $p;
        }

        $out = [];
        foreach ($groups as $passes) {
            usort($passes, fn (ScrapedEvent $a, ScrapedEvent $b) => $a->start <=> $b->start);
            $first = $passes[0];

            if (count($passes) === 1) {
                $out[] = $first;
                continue;
            }

            $last = $passes[count($passes) - 1];
            // Hasta el final del último día: el feed lo enseña mientras quede
            // alguna función, y ese día hay una.
            $end = ($last->end ?? $last->start)->setTime(23, 59);

            $out[] = new ScrapedEvent(
                source: $first->source,
                externalId: $first->externalId,
                title: $first->title,
                start: $first->start,
                end: $end,
                city: $first->city,
                venueName: $first->venueName,
                latitude: $first->latitude,
                longitude: $first->longitude,
                link: $first->link,
                linkAction: $first->linkAction,
                description: $first->description,
                imageUrl: $first->imageUrl,
                detailUrl: $first->detailUrl,
                // Los días en que hay pase: el filtro de jueves a sábado mira
                // si alguno cae dentro, no sólo el rango entero.
                weekdays: array_values(array_unique(array_map(fn (ScrapedEvent $p) => (int) $p->start->format('N'), $passes))),
                venueAddress: $first->venueAddress,
                subcategory: $first->subcategory,
                subtype: $first->subtype,
            );
        }

        return $out;
    }
}
