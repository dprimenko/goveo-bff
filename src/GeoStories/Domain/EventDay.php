<?php

declare(strict_types=1);

namespace App\GeoStories\Domain;

/**
 * El día (y, si se quiere, la hora) que se mira en la pestaña de Eventos.
 *
 * Por defecto es hoy. Quien busca plan para el sábado elige el sábado, y con
 * hora («a partir de las 20:00») si quiere; **la hora es opcional**: sin ella
 * vale el día entero.
 *
 * Qué entra: lo que **está en marcha en algún momento de ese día**, también los
 * largos que empezaron antes; con hora, lo que sigue en marcha o empieza desde
 * esa hora hasta que acaba el día. Es el día de Madrid, no de UTC.
 *
 * Sólo hacia delante: un día pasado se trata como hoy.
 */
final class EventDay
{
    public const TIMEZONE = 'Europe/Madrid';

    private function __construct(
        /** 00:00 del día elegido, en UTC. */
        public readonly \DateTimeImmutable $dayStart,
        /** 00:00 del día siguiente, en UTC. */
        public readonly \DateTimeImmutable $dayEnd,
        /** Desde cuándo: la hora elegida o, sin ella, el principio del día. */
        public readonly \DateTimeImmutable $from,
        public readonly bool $hasTime,
    ) {}

    /**
     * De la query (`date=2026-10-04`, `time=20:00`). `null` si no hay fecha, si
     * no se entiende o si es hoy o antes: entonces el feed es el de siempre.
     */
    public static function fromQuery(?string $date, ?string $time, ?\DateTimeImmutable $now = null): ?self
    {
        $date = trim((string) $date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $zone  = new \DateTimeZone(self::TIMEZONE);
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if ($start === false || $start->format('Y-m-d') !== $date) {
            return null;
        }

        $today = ($now ?? new \DateTimeImmutable())->setTimezone($zone)->setTime(0, 0);
        $time  = trim((string) $time);
        $hasTime = preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m) === 1;

        // Hoy sin hora es el feed de siempre; hoy con hora sí filtra.
        if ($start < $today || ($start == $today && !$hasTime)) {
            return null;
        }

        $from = $hasTime ? $start->setTime((int) $m[1], (int) $m[2]) : $start;
        $utc  = new \DateTimeZone('UTC');

        return new self(
            $start->setTimezone($utc),
            $start->modify('+1 day')->setTimezone($utc),
            $from->setTimezone($utc),
            $hasTime,
        );
    }
}
