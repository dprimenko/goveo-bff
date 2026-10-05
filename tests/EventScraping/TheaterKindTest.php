<?php

declare(strict_types=1);

namespace App\Tests\EventScraping;

use App\EventScraping\Application\TheaterKind;
use PHPUnit\Framework\TestCase;

/** Grandes teatros, Salas o centros culturales, con fuentes y salas de verdad. */
final class TheaterKindTest extends TestCase
{
    public function testSingleTheaterSourcesGoWhole(): void
    {
        self::assertSame([TheaterKind::BIG, null], TheaterKind::of('stage', 'Teatro Lope de Vega', 'events-stage-musicals'));
        self::assertSame([TheaterKind::BIG, null], TheaterKind::of('teatro-lara', 'Teatro Lara', 'events-stage-theater'));
        self::assertSame([TheaterKind::HALLS, null], TheaterKind::of('microteatro', 'Microteatro por Dinero', 'events-stage-microtheater'));
    }

    public function testAgendasDecideByVenue(): void
    {
        // Las Naves del Español están en Matadero, pero son de los grandes.
        self::assertSame([TheaterKind::BIG, null], TheaterKind::of('madrid-datos', 'Naves del Español en Matadero', null));
        self::assertSame(
            [TheaterKind::CULTURAL, 'events-stage-comedy'],
            TheaterKind::of('madrid-datos', 'Centro Cultural Casa del Reloj', 'events-stage-comedy'),
        );
        self::assertSame([TheaterKind::BIG, null], TheaterKind::of('esmadrid', 'Teatro Reina Victoria', null));
        self::assertSame([TheaterKind::HALLS, null], TheaterKind::of('esmadrid', 'Sala Mirador', null));
    }

    public function testEverythingElseStaysInCulturalCentresWithoutTheTheaterSubtype(): void
    {
        self::assertSame([TheaterKind::CULTURAL, null], TheaterKind::of('red-teatros-municipios', 'Teatro Auditorio Municipal', 'events-stage-theater'));
        self::assertSame([TheaterKind::CULTURAL, 'events-stage-dance'], TheaterKind::of('alcala', 'Teatro Salón Cervantes', 'events-stage-dance'));
    }
}
