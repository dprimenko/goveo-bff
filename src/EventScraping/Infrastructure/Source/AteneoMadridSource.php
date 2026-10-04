<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Infrastructure\Html;

/**
 * Ateneo de Madrid (ateneodemadrid.com), por la API de su calendario de
 * WordPress (ver `TribeEventsSource`).
 *
 * La mayor parte de su agenda son conferencias, mesas redondas y desayunos con
 * políticos: actos de socios y de estudio, no un plan para el público del feed.
 * Se toma sólo lo que sí lo es, por la categoría que les pone la web (`TYPES`):
 * exposiciones, cine, visitas, conciertos de su ciclo de cámara, teatro y lo
 * literario (presentaciones, lecturas, clubes de lectura).
 *
 * - **Los conciertos de jazz del Ateneo los programa el Café Central** en La
 *   Cátedra y ya los lee `cafe-central`. Van con la categoría «Conciertos
 *   Ateneo-Café Central» o, a veces, con la genérica «Concierto» y el título en
 *   mayúsculas («ASTRID JONES & THE BLUE FLAPS»), que es como los escribe el
 *   Café. Esos se quitan; los de cámara («Silva de Sirenas», «Ciclo El Salón del
 *   Ateneo») se escriben en minúsculas y se quedan.
 * - La API deja el resumen vacío: la descripción sale del `og:description` de
 *   la ficha (sala y hora, quién interviene).
 * - Una exposición de varios días viene con la hora de apertura en el inicio y
 *   en el fin; el rango ya es el que es.
 */
final class AteneoMadridSource extends TribeEventsSource
{
    use TribeShows;

    /** Categoría de la web (en minúsculas) → tipo y subnivel. La primera que case. */
    private const TYPES = [
        'exposición'             => ['events-art', 'events-art-temporary'],
        'cine'                   => ['events-experiences', 'events-experiences-cinema'],
        'visita'                 => ['events-experiences', 'events-experiences-guided-tours'],
        'espectáculo'            => ['events-stage', null],
        'lectura dramatizada'    => ['events-stage', 'events-stage-theater'],
        'monólogo'               => ['events-stage', 'events-stage-comedy'],
        'musical'                => ['events-stage', 'events-stage-musicals'],
        'ópera'                  => ['events-small-concerts', null],
        'concierto'              => ['events-small-concerts', null],
        'candlelight'            => ['events-small-concerts', null],
        'música'                 => ['events-small-concerts', null],
        'presentación del libro' => ['events-experiences', null],
        'presentación de libro'  => ['events-experiences', null],
        'lectura'                => ['events-experiences', null],
        'lectura de poemas'      => ['events-experiences', null],
        'club de lectura'        => ['events-experiences', null],
    ];

    public function name(): string
    {
        return 'ateneo-madrid';
    }

    public function fetch(): iterable
    {
        $events = [];
        foreach (parent::fetch() as $event) {
            // `classify` recibe el título en minúsculas: lo del Café se mira aquí.
            if ($event->subcategory === 'events-small-concerts' && $this->writtenByCafeCentral($event->title)) {
                continue;
            }
            $events[] = $event;
        }

        return $this->groupShows($events);
    }

    /** La API no trae resumen: el `og:description` de la ficha. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        $html = $event->detailUrl !== null ? $this->web->get($event->detailUrl) : null;
        if ($html === null) {
            return $event;
        }

        $description = Html::clean(Html::attr(Html::xpath($html), '//meta[@property="og:description"]', 'content'));

        return $event->withDetails(null, $description);
    }

    protected function site(): string
    {
        return 'https://ateneodemadrid.com';
    }

    protected function venue(): array
    {
        return [
            'name'     => 'Ateneo de Madrid',
            'lat'      => 40.4151573,
            'lng'      => -3.6982165,
            'address'  => 'Calle del Prado, 21, 28014 Madrid',
            'category' => 'culture-shows',
        ];
    }

    /**
     * Sin tipo se descarta (`TribeShows`): conferencias, coloquios, jornadas…
     * El «Ciclo» a secas sólo es plan si es de cine: hay ciclos de conferencias.
     */
    protected function classify(string $title, array $categories): array
    {
        if (in_array('conciertos ateneo-café central', $categories, true)) {
            return [null, null];
        }
        if (in_array('ciclo', $categories, true) && str_contains($title, 'cine')) {
            return ['events-experiences', 'events-experiences-cinema'];
        }

        foreach (self::TYPES as $category => $type) {
            if (in_array($category, $categories, true)) {
                return $type;
            }
        }

        return [null, null];
    }

    /** Todo en mayúsculas, como titula el Café Central («PEPE RIVERO TRÍO»). */
    private function writtenByCafeCentral(string $title): bool
    {
        $letters = preg_replace('/[^\p{L}]+/u', '', html_entity_decode($title)) ?? '';

        return $letters !== '' && mb_strtoupper($letters) === $letters;
    }
}
