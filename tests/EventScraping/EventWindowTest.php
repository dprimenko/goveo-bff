<?php

declare(strict_types=1);

namespace App\Tests\EventScraping;

use App\EventScraping\Application\EventWindow;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Infrastructure\Source\SpanishDate;
use PHPUnit\Framework\TestCase;

/**
 * **Qué entra en cada pasada del scraping**: de jueves a sábado y en los
 * próximos 30 días. Lo que se prueba es lo que no se ve mirando la cola: que un
 * evento largo entra aunque empezara hace un mes, y que uno que se repite los
 * martes no se cuela por durar varias semanas.
 */
final class EventWindowTest extends TestCase
{
    private const THU_FRI_SAT = [4, 5, 6];

    private \DateTimeZone $tz;

    protected function setUp(): void
    {
        $this->tz = new \DateTimeZone('Europe/Madrid');
    }

    /** Del miércoles 23 de septiembre de 2026 al 23 de octubre. */
    private function window(): EventWindow
    {
        return new EventWindow(
            new \DateTimeImmutable('2026-09-23 00:00', $this->tz),
            new \DateTimeImmutable('2026-10-23 23:59', $this->tz),
            self::THU_FRI_SAT,
        );
    }

    private function event(string $start, ?string $end = null, ?array $weekdays = null): ScrapedEvent
    {
        return new ScrapedEvent(
            source: 'test',
            externalId: 'x',
            title: 'Evento',
            start: new \DateTimeImmutable($start, $this->tz),
            end: $end !== null ? new \DateTimeImmutable($end, $this->tz) : null,
            city: 'Madrid',
            venueName: 'Sala',
            latitude: null,
            longitude: null,
            link: 'https://example.com',
            weekdays: $weekdays,
        );
    }

    public function testAConcertOnFridayEnters(): void
    {
        self::assertTrue($this->window()->accepts($this->event('2026-09-25 22:00')));
    }

    public function testAConcertOnWednesdayStaysOut(): void
    {
        self::assertFalse($this->window()->accepts($this->event('2026-09-30 21:00')));
    }

    public function testAnEventBeyondThirtyDaysStaysOut(): void
    {
        self::assertFalse($this->window()->accepts($this->event('2026-11-06 21:00')));
    }

    public function testAnExhibitionThatStartedLastMonthStillEnters(): void
    {
        self::assertTrue($this->window()->accepts($this->event('2026-09-01 10:00', '2027-01-24 20:00')));
    }

    public function testSomethingAlreadyOverStaysOut(): void
    {
        self::assertFalse($this->window()->accepts($this->event('2026-09-10 10:00', '2026-09-20 20:00')));
    }

    public function testARecurringEventOnlyCountsItsOwnDays(): void
    {
        // Todos los martes durante un mes: dura muchos jueves, pero no cae en ninguno.
        self::assertFalse($this->window()->accepts($this->event('2026-09-22 19:00', '2026-10-20 21:00', [2])));
        self::assertTrue($this->window()->accepts($this->event('2026-09-22 19:00', '2026-10-20 21:00', [2, 4])));
    }

    public function testAMonthWithoutYearRollsOverToNextYear(): void
    {
        $today = new \DateTimeImmutable('2026-09-23', $this->tz);

        self::assertSame('2027-01-15 20:30', SpanishDate::build(15, 1, '20:30', $today)?->format('Y-m-d H:i'));
        // El mes pasado sigue siendo este año: un evento de la semana pasada que
        // aún esté en la lista no puede saltar al año que viene.
        self::assertSame('2026-08-30 00:00', SpanishDate::build(30, 8, null, $today)?->format('Y-m-d H:i'));
        self::assertSame(9, SpanishDate::month('Sep'));
        self::assertSame(10, SpanishDate::month('October'));
    }
}
