<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Metrics;

/**
 * El mes natural en curso y el anterior, **en hora de Madrid**.
 *
 * Las fechas se guardan en UTC, y cortar el mes por UTC mete en septiembre lo
 * que en España se subió el 1 de octubre entre las 00:00 y las 02:00. Para un
 * recuento mensual eso es ruido que alguien acaba preguntando, así que los
 * límites se calculan en `Europe/Madrid` y se pasan a la consulta ya en UTC.
 *
 * Los intervalos son semiabiertos, `[desde, hasta)`: el primer instante del mes
 * siguiente no es de este mes. Así se evita el «23:59:59» y los segundos que se
 * quedan fuera.
 *
 * El mes anterior es **entero**, no «hasta el mismo día»: a mitad de mes la
 * comparación favorece al anterior, y la pantalla lo dice.
 */
final class MonthWindow
{
    public const TIMEZONE = 'Europe/Madrid';

    private function __construct(
        /** Primer instante del mes en curso. */
        public readonly \DateTimeImmutable $currentStart,
        /** Primer instante del mes siguiente: donde acaba el mes en curso. */
        public readonly \DateTimeImmutable $currentEnd,
        /** Primer instante del mes anterior. Acaba donde empieza el en curso. */
        public readonly \DateTimeImmutable $previousStart,
        public readonly \DateTimeImmutable $now,
    ) {}

    public static function at(\DateTimeImmutable $now): self
    {
        $local = $now->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $start = $local->modify('first day of this month')->setTime(0, 0);

        return new self(
            currentStart:  $start,
            currentEnd:    $start->modify('first day of next month'),
            previousStart: $start->modify('first day of previous month'),
            now:           $local,
        );
    }

    /** `2026-09`: lo que la pantalla convierte en «septiembre de 2026». */
    public function currentLabel(): string
    {
        return $this->currentStart->format('Y-m');
    }

    public function previousLabel(): string
    {
        return $this->previousStart->format('Y-m');
    }

    /**
     * Los límites como parámetros de la consulta, en UTC y con el desfase
     * explícito: Postgres compara `timestamptz` por instante, así que da igual
     * la zona de la sesión.
     *
     * @return array{cur_from: string, cur_to: string, prev_from: string}
     */
    public function sqlParams(): array
    {
        $utc = new \DateTimeZone('UTC');

        return [
            'cur_from'  => $this->currentStart->setTimezone($utc)->format(DATE_ATOM),
            'cur_to'    => $this->currentEnd->setTimezone($utc)->format(DATE_ATOM),
            'prev_from' => $this->previousStart->setTimezone($utc)->format(DATE_ATOM),
        ];
    }

    /**
     * Los límites como **fechas de Madrid** (`Y-m-d`), para las tablas que ya
     * guardan el día local en vez de un instante (`app_installs_daily`).
     *
     * @return array{prev_day: string, cur_day: string, next_day: string}
     */
    public function dayParams(): array
    {
        return [
            'prev_day' => $this->previousStart->format('Y-m-d'),
            'cur_day'  => $this->currentStart->format('Y-m-d'),
            'next_day' => $this->currentEnd->format('Y-m-d'),
        ];
    }
}
