<?php

declare(strict_types=1);

namespace App\EventScraping\Application;

use App\EventScraping\Domain\ScrapedEvent;

/**
 * Qué eventos merecen entrar en esta pasada.
 *
 * El criterio es el de la cartelera que se preparaba a mano: **de jueves a
 * sábado y en los próximos 30 días**. Un evento largo —una exposición de tres
 * meses, un musical en cartel— entra si alguno de sus días cae dentro; uno que
 * se repite («los jueves») entra si alguno de *esos* días cae dentro.
 */
final class EventWindow
{
    /**
     * @param list<int> $weekdays Días aceptados (ISO: 1 lunes … 7 domingo).
     */
    public function __construct(
        private readonly \DateTimeImmutable $from,
        private readonly \DateTimeImmutable $to,
        private readonly array $weekdays,
    ) {}

    /** @param list<int> $weekdays */
    public static function nextDays(int $days, array $weekdays): self
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));

        return new self($today, $today->modify(sprintf('+%d days', $days))->setTime(23, 59, 59), $weekdays);
    }

    public function accepts(ScrapedEvent $event): bool
    {
        $end = $event->end ?? $event->start;

        // Lo que ya terminó, o no empieza hasta después de la ventana.
        if ($end < $this->from || $event->start > $this->to) {
            return false;
        }

        $day  = max($event->start, $this->from)->setTime(0, 0);
        $last = min($end, $this->to);

        // Una semana basta para saber si algún día acertado cae dentro; el tope
        // es para que un rango de un año no dé cientos de vueltas.
        for ($i = 0; $i < 62 && $day <= $last; ++$i, $day = $day->modify('+1 day')) {
            $weekday = (int) $day->format('N');
            if (in_array($weekday, $this->weekdays, true)
                && ($event->weekdays === null || in_array($weekday, $event->weekdays, true))
            ) {
                return true;
            }
        }

        return false;
    }
}
