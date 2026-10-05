<?php

declare(strict_types=1);

namespace App\Tests\EventScraping;

use App\EventScraping\Application\TheaterKind;
use PHPUnit\Framework\TestCase;

/** El subnivel de Teatro y escena, con fuentes y salas de verdad. */
final class TheaterKindTest extends TestCase
{
    private const STAGE = 'events-stage';

    public function testTheGenreWinsOverTheVenue(): void
    {
        // Un musical de la Gran Vía sale en «Musicales», no en «grandes salas».
        self::assertSame([self::STAGE, 'events-stage-musicals'], TheaterKind::of('stage', 'Teatro Lope de Vega', self::STAGE, 'events-stage-musicals'));
        self::assertSame([self::STAGE, 'events-stage-microtheater'], TheaterKind::of('microteatro', 'Microteatro por Dinero', self::STAGE, 'events-stage-microtheater'));
        self::assertSame([self::STAGE, TheaterKind::CIRCUS], TheaterKind::of('madrid-datos', 'Teatro Circo Price', 'events-circus', null));
    }

    public function testWithoutGenreItGoesByVenue(): void
    {
        self::assertSame([self::STAGE, TheaterKind::BIG], TheaterKind::of('teatro-lara', 'Teatro Lara', self::STAGE, 'events-stage-theater'));
        self::assertSame([self::STAGE, TheaterKind::HALLS], TheaterKind::of('teseo-teatro', 'Teseo Teatro', self::STAGE, null));
        // Las Naves del Español están en Matadero, pero son de las grandes.
        self::assertSame([self::STAGE, TheaterKind::BIG], TheaterKind::of('madrid-datos', 'Naves del Español en Matadero', self::STAGE, null));
        self::assertSame([self::STAGE, TheaterKind::CULTURAL], TheaterKind::of('madrid-datos', 'Centro Cultural Casa del Reloj', self::STAGE, null));
        self::assertSame([self::STAGE, TheaterKind::BIG], TheaterKind::of('esmadrid', 'Teatro Reina Victoria', self::STAGE, null));
        self::assertSame([self::STAGE, TheaterKind::HALLS], TheaterKind::of('esmadrid', 'Sala Mirador', self::STAGE, null));
        self::assertSame([self::STAGE, TheaterKind::CULTURAL], TheaterKind::of('red-teatros-municipios', 'Teatro Auditorio Municipal', self::STAGE, null));
    }
}
