<?php

declare(strict_types=1);

namespace App\Tests\EventScraping;

use App\EventScraping\Infrastructure\ICal;
use PHPUnit\Framework\TestCase;

final class ICalTest extends TestCase
{
    public function testReadsEventsWithFoldedLinesAndEscapes(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;TZID=Europe/Madrid:20261031T120000\r\n"
            . "SUMMARY:Cuentacuentos en Inglés\r\nLOCATION:Biblioteca Municipal\\, Plaza de Francisco Rabal\\, 2\r\n"
            . "ATTACH;FMTTYPE=image/png:https://www.alpedrete.es/wp-content/uploads/2026/10/cartel-cuen\r\n tos.png\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";

        $events = ICal::events($ics);

        self::assertCount(1, $events);
        self::assertSame('Biblioteca Municipal, Plaza de Francisco Rabal, 2', $events[0]['LOCATION']);
        self::assertSame('https://www.alpedrete.es/wp-content/uploads/2026/10/cartel-cuentos.png', $events[0]['ATTACH']);
    }

    public function testTheTimeIsMadridEvenWithATrailingZ(): void
    {
        // Boadilla escribe `TZID=Europe/Madrid:20261023T180000Z` para una
        // función a las 18:00 de Madrid: la Z no es UTC.
        $tz = new \DateTimeZone('Europe/Madrid');

        self::assertSame('2026-10-23 18:00', ICal::date('20261023T180000Z', $tz)?->format('Y-m-d H:i'));
        self::assertSame('2026-10-23 00:00', ICal::date('20261023', $tz)?->format('Y-m-d H:i'));
        self::assertNull(ICal::date('mañana', $tz));
    }
}
