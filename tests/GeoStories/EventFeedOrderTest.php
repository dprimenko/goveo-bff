<?php

declare(strict_types=1);

namespace App\Tests\GeoStories;

use App\GeoStories\Infrastructure\Repository\EventFeedOrder;
use PHPUnit\Framework\TestCase;

/**
 * El reparto de la pestaña de Eventos: 5 normales, 1 recurrente, y vuelta.
 */
final class EventFeedOrderTest extends TestCase
{
    public function testFiveNormalThenOneRecurring(): void
    {
        self::assertSame('NNNNNRNNNNNRNNNNNR', $this->pattern(normal: 15, recurring: 3));
    }

    public function testWhenRecurringRunOutNormalsGoOnWithoutGaps(): void
    {
        self::assertSame('NNNNNRNNNNNNNNN', $this->pattern(normal: 14, recurring: 1));
    }

    public function testWhenNormalsRunOutTheRestOfRecurringFollow(): void
    {
        // Pocos eventos de hoy y muchos recurrentes: salen todos, sin huecos.
        self::assertSame('NNNNNRNNRRR', $this->pattern(normal: 7, recurring: 4));
    }

    public function testOnlyRecurring(): void
    {
        self::assertSame('RRR', $this->pattern(normal: 0, recurring: 3));
    }

    public function testEveryEventHasItsOwnPlace(): void
    {
        // Con LIMIT/OFFSET, dos eventos en el mismo puesto harían que el scroll
        // infinito repitiera o se saltara uno.
        $places = [];
        for ($i = 0; $i < 50; ++$i) {
            $places[] = EventFeedOrder::position(false, $i);
            $places[] = EventFeedOrder::position(true, $i);
        }

        self::assertSame(count($places), count(array_unique($places)));
    }

    /** La lista resultante, `N` normal y `R` recurrente, ordenada por puesto. */
    private function pattern(int $normal, int $recurring): string
    {
        $items = [];
        for ($i = 0; $i < $normal; ++$i) {
            $items[] = [EventFeedOrder::position(false, $i), 'N'];
        }
        for ($i = 0; $i < $recurring; ++$i) {
            $items[] = [EventFeedOrder::position(true, $i), 'R'];
        }
        usort($items, static fn (array $a, array $b) => $a[0] <=> $b[0]);

        return implode('', array_column($items, 1));
    }
}
