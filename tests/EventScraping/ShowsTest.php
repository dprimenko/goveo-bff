<?php

declare(strict_types=1);

namespace App\Tests\EventScraping;

use App\EventScraping\Application\Shows;
use App\EventScraping\Domain\ScrapedEvent;
use PHPUnit\Framework\TestCase;

/**
 * Los pases de un mismo espectáculo salen como **un solo evento con su rango**,
 * del primer pase que queda al último día: lo pidió así el socio, porque uno por
 * función llenaba la cola y el feed de copias.
 */
final class ShowsTest extends TestCase
{
    private function pass(string $id, string $start): ScrapedEvent
    {
        $tz = new \DateTimeZone('Europe/Madrid');

        return new ScrapedEvent(
            source: 'test',
            externalId: $id,
            title: 'Espectáculo ' . $id,
            start: new \DateTimeImmutable($start, $tz),
            end: null,
            city: 'Madrid',
            venueName: 'Sala',
            latitude: 40.4,
            longitude: -3.7,
            link: 'https://example.com/' . $id,
        );
    }

    public function testThePassesOfAShowBecomeOneEventSpanningThem(): void
    {
        $in = new \DateTimeImmutable('+2 days', new \DateTimeZone('Europe/Madrid'));
        $shows = Shows::group([
            $this->pass('six', $in->format('Y-m-d') . ' 20:00'),
            $this->pass('six', $in->modify('+7 days')->format('Y-m-d') . ' 17:00'),
            $this->pass('six', $in->modify('+1 day')->format('Y-m-d') . ' 20:00'),
        ]);

        self::assertCount(1, $shows);
        self::assertSame($in->format('Y-m-d') . ' 20:00', $shows[0]->start->format('Y-m-d H:i'));
        self::assertSame($in->modify('+7 days')->format('Y-m-d') . ' 23:59', $shows[0]->end?->format('Y-m-d H:i'));
    }

    public function testASingleShowIsLeftAsItWas(): void
    {
        $when = (new \DateTimeImmutable('+3 days'))->format('Y-m-d') . ' 21:00';
        $shows = Shows::group([$this->pass('a', $when), $this->pass('b', $when)]);

        self::assertCount(2, $shows);
        self::assertNull($shows[0]->end);
    }

    public function testPassesAlreadyGoneAreLeftOut(): void
    {
        $shows = Shows::group([
            $this->pass('six', '-3 days 20:00'),
            $this->pass('six', '+4 days 20:00'),
        ]);

        self::assertCount(1, $shows);
        self::assertGreaterThan(new \DateTimeImmutable(), $shows[0]->start);
    }
}
