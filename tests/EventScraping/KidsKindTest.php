<?php

declare(strict_types=1);

namespace App\Tests\EventScraping;

use App\EventScraping\Application\KidsKind;
use PHPUnit\Framework\TestCase;

/**
 * Lo que las agendas municipales dejan entrar: sólo lo de niños. Una agenda de
 * pueblo trae plenos, talleres de mayores y la feria taurina en la misma lista.
 */
final class KidsKindTest extends TestCase
{
    public function testStorytellingIsForKids(): void
    {
        self::assertSame(['events-kids', 'events-kids-storytelling'], KidsKind::of('Cuentacuentos en Inglés (Room on the Broom)'));
        self::assertSame(['events-kids', 'events-kids-storytelling'], KidsKind::of('Bebecuento en la biblioteca: BESOS Y MIMITOS'));
        self::assertSame(['events-kids', 'events-kids-storytelling'], KidsKind::of('Cuentaller en la Biblioteca'));
    }

    public function testWorkshopsAndShowsGoToTheirSubtype(): void
    {
        self::assertSame(['events-kids', 'events-kids-workshops'], KidsKind::of('Taller Infantil Madrid lee en Comunidad'));
        self::assertSame(['events-kids', 'events-kids-theater'], KidsKind::of('Teatro familiar de títeres y movimiento'));
    }

    public function testTheRestOfATownAgendaStaysOut(): void
    {
        self::assertNull(KidsKind::of('Pleno Extraordinario'));
        self::assertNull(KidsKind::of('Taller para Mayores: «Vivir es descubrirme»'));
        self::assertNull(KidsKind::of('Feria Taurina Moralzarzal 2026'));
    }
}
