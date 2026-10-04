<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

/**
 * Museo Cerralbo. Su categoría es casi siempre la edad («De 6 a 12», «Más de
 * 18»), así que el tipo sale del título (ver la base).
 */
final class MuseoCerralboSource extends MinisterioCulturaAgendaSource
{
    public function name(): string
    {
        return 'museo-cerralbo';
    }

    protected function agendaUrl(): string
    {
        return 'https://www.cultura.gob.es/mcerralbo/actividades/programacion-en-curso.html';
    }

    protected function venue(): array
    {
        return [
            'name'    => 'Museo Cerralbo',
            'lat'     => 40.4237732,
            'lng'     => -3.7145417,
            'address' => 'Calle de Ventura Rodríguez, 17, 28008 Madrid',
            'website' => 'https://www.cultura.gob.es/mcerralbo/',
        ];
    }
}
