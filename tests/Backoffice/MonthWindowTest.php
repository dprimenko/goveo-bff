<?php

declare(strict_types=1);

namespace App\Tests\Backoffice;

use App\Backoffice\Application\Metrics\MonthWindow;
use PHPUnit\Framework\TestCase;

/**
 * **El mes se corta en hora de Madrid, no en UTC.**
 *
 * Las fechas se guardan en UTC, y cortar por ahí mete en septiembre lo que en
 * España se subió el 1 de octubre de madrugada. Estos casos fijan los bordes:
 * la primera media hora del mes, el cambio de hora y el salto de año.
 */
final class MonthWindowTest extends TestCase
{
    public function testMidMonth(): void
    {
        $window = MonthWindow::at(new \DateTimeImmutable('2026-09-29T15:38:00Z'));

        self::assertSame('2026-09', $window->currentLabel());
        self::assertSame('2026-08', $window->previousLabel());
        self::assertSame([
            'cur_from'  => '2026-08-31T22:00:00+00:00',
            'cur_to'    => '2026-09-30T22:00:00+00:00',
            'prev_from' => '2026-07-31T22:00:00+00:00',
        ], $window->sqlParams());
    }

    public function testFirstMinutesOfTheMonthInMadridAreAlreadyTheNewMonth(): void
    {
        // En UTC todavía es 30 de septiembre; en Madrid ya es 1 de octubre.
        $window = MonthWindow::at(new \DateTimeImmutable('2026-09-30T22:30:00Z'));

        self::assertSame('2026-10', $window->currentLabel());
        self::assertSame('2026-09', $window->previousLabel());
    }

    public function testDaylightSavingChangeInsideThePreviousMonth(): void
    {
        // El 25 de octubre se pasa de +02:00 a +01:00: noviembre empieza a las
        // 23:00 UTC y octubre, a las 22:00 UTC.
        $window = MonthWindow::at(new \DateTimeImmutable('2026-11-10T09:00:00Z'));

        self::assertSame([
            'cur_from'  => '2026-10-31T23:00:00+00:00',
            'cur_to'    => '2026-11-30T23:00:00+00:00',
            'prev_from' => '2026-09-30T22:00:00+00:00',
        ], $window->sqlParams());
    }

    public function testJanuaryComparesWithDecemberOfThePreviousYear(): void
    {
        $window = MonthWindow::at(new \DateTimeImmutable('2027-01-15T12:00:00Z'));

        self::assertSame('2027-01', $window->currentLabel());
        self::assertSame('2026-12', $window->previousLabel());
    }
}
