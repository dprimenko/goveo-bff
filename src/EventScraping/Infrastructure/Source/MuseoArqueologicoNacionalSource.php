<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

/**
 * Museo Arqueológico Nacional: exposiciones temporales, visitas, talleres,
 * cine y actividades de familia de su agenda (ver la base).
 *
 * La agenda trae mucho de investigación —congresos, mesas redondas, ciclos de
 * conferencias— que la base deja fuera por la categoría.
 */
final class MuseoArqueologicoNacionalSource extends MinisterioCulturaAgendaSource
{
    public function name(): string
    {
        return 'museo-arqueologico';
    }

    protected function agendaUrl(): string
    {
        return 'https://www.man.es/man/actividades/agenda.html';
    }

    protected function venue(): array
    {
        return [
            'name'    => 'Museo Arqueológico Nacional',
            'lat'     => 40.4233265,
            'lng'     => -3.6888235,
            'address' => 'Calle de Serrano, 13, 28001 Madrid',
            'website' => 'https://www.man.es/',
        ];
    }

    /** «Visita autónoma»: un itinerario para hacer solo, sin cita. */
    protected function excluded(array $card): bool
    {
        return (bool) preg_match('/visita aut[oó]noma/iu', $card['when']);
    }
}
