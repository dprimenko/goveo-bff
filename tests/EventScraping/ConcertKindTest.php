<?php

declare(strict_types=1);

namespace App\Tests\EventScraping;

use App\EventScraping\Application\ConcertKind;
use PHPUnit\Framework\TestCase;

/** Clásica o moderna, con títulos que han salido de verdad en las fuentes. */
final class ConcertKindTest extends TestCase
{
    public function testClassicalConcertsAreRecognised(): void
    {
        foreach ([
            'OCNE. Sinfónico 02 Auditorio Nacional de Música',
            'All Bach (III) – Silva de Sirenas Festival',
            'Concierto de cámara: Cuarteto de cuerda',
            'Recital de piano',
            'Orquesta y Coro de la Comunidad de Madrid',
        ] as $text) {
            self::assertSame(ConcertKind::CLASSICAL, ConcertKind::of($text), $text);
        }
    }

    public function testEverythingElseIsModern(): void
    {
        foreach ([
            'JORDI ÉVOLE Y LOS NIÑOS JESÚS Sala El Sol',
            'Lucía Rey Quartet Círculo de Bellas Artes',
            'Loma Prieta + Crossed + Nada de Valor',
            'OCELOT JAM Intruso Bar',
        ] as $text) {
            self::assertSame(ConcertKind::MODERN, ConcertKind::of($text), $text);
        }
    }
}
