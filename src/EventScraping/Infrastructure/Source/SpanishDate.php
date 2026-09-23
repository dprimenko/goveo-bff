<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

/**
 * Fechas de listado de sala: «23 Sep», «2 October», sin año.
 *
 * El año se deduce: si el mes ya pasó hace más de uno, es del año que viene
 * (en septiembre, «Ene» es enero próximo). El margen de un mes es para no
 * mandar al año siguiente un evento de la semana pasada que siga en la lista.
 */
final class SpanishDate
{
    private const MONTHS = [
        'ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'ago' => 8, 'sep' => 9, 'set' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12,
        'jan' => 1, 'apr' => 4, 'aug' => 8, 'dec' => 12,
    ];

    public static function month(string $name): ?int
    {
        $key = mb_substr(mb_strtolower(trim($name)), 0, 3);

        return self::MONTHS[$key] ?? null;
    }

    public static function build(int $day, int $month, ?string $time, ?\DateTimeImmutable $today = null): ?\DateTimeImmutable
    {
        $tz    = new \DateTimeZone('Europe/Madrid');
        $today ??= new \DateTimeImmutable('today', $tz);
        $year  = (int) $today->format('Y');

        if ($month < (int) $today->format('n') - 1) {
            ++$year;
        }
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        $date = (new \DateTimeImmutable('now', $tz))->setDate($year, $month, $day)->setTime(0, 0);

        // «23:59» en las salas es la sesión de club que empieza a medianoche:
        // se deja tal cual, en el día que anuncian.
        if ($time !== null && preg_match('/(\d{1,2})[:.](\d{2})/', $time, $m)) {
            return $date->setTime((int) $m[1], (int) $m[2]);
        }

        return $date;
    }
}
