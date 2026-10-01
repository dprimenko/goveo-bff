<?php

declare(strict_types=1);

namespace App\Tests\GeoStories;

use App\GeoStories\Domain\EventDay;
use PHPUnit\Framework\TestCase;

/** El día que se mira en la pestaña de Eventos: hacia delante, hora opcional. */
final class EventDayTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        // Miércoles 1 de octubre de 2026, 11:00 en Madrid (09:00 UTC).
        $this->now = new \DateTimeImmutable('2026-10-01T09:00:00Z');
    }

    public function testAFutureDayWithoutTimeIsTheWholeDayInMadrid(): void
    {
        $day = EventDay::fromQuery('2026-10-04', null, $this->now);

        self::assertNotNull($day);
        self::assertFalse($day->hasTime);
        // 00:00 en Madrid (CEST, UTC+2) es 22:00 UTC del día anterior.
        self::assertSame('2026-10-03T22:00:00+00:00', $day->dayStart->format(DATE_ATOM));
        self::assertSame('2026-10-04T22:00:00+00:00', $day->dayEnd->format(DATE_ATOM));
        self::assertSame('2026-10-03T22:00:00+00:00', $day->from->format(DATE_ATOM));
    }

    public function testTheTimeIsOptionalAndMovesTheStart(): void
    {
        $day = EventDay::fromQuery('2026-10-04', '20:00', $this->now);

        self::assertTrue($day->hasTime);
        self::assertSame('2026-10-04T18:00:00+00:00', $day->from->format(DATE_ATOM));
        self::assertSame('2026-10-04T22:00:00+00:00', $day->dayEnd->format(DATE_ATOM));
    }

    public function testTodayWithoutTimeIsTheUsualFeed(): void
    {
        self::assertNull(EventDay::fromQuery('2026-10-01', null, $this->now));
    }

    public function testTodayWithTimeDoesFilter(): void
    {
        self::assertNotNull(EventDay::fromQuery('2026-10-01', '21:30', $this->now));
    }

    public function testPastOrBrokenDatesAreIgnored(): void
    {
        self::assertNull(EventDay::fromQuery('2026-09-30', null, $this->now));
        self::assertNull(EventDay::fromQuery('2026-02-30', null, $this->now));
        self::assertNull(EventDay::fromQuery('mañana', null, $this->now));
        self::assertNull(EventDay::fromQuery(null, '20:00', $this->now));
    }

    public function testABrokenTimeMeansTheWholeDay(): void
    {
        $day = EventDay::fromQuery('2026-10-04', '25:00', $this->now);

        self::assertFalse($day->hasTime);
    }
}
