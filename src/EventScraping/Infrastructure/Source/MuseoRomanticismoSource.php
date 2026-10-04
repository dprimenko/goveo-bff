<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

/**
 * Museo del Romanticismo: exposiciones, conciertos, visitas guiadas y
 * talleres de familia de su calendario (ver la base).
 */
final class MuseoRomanticismoSource extends MinisterioCulturaAgendaSource
{
    public function name(): string
    {
        return 'museo-romanticismo';
    }

    protected function agendaUrl(): string
    {
        return 'https://www.cultura.gob.es/mromanticismo/programacion/actividades/calendario.html';
    }

    protected function venue(): array
    {
        return [
            'name'    => 'Museo del Romanticismo',
            'lat'     => 40.4258946,
            'lng'     => -3.6987643,
            'address' => 'Calle de San Mateo, 13, 28004 Madrid',
            'website' => 'https://www.cultura.gob.es/mromanticismo/',
        ];
    }
}
