<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Repository;

/**
 * El orden de la pestaña de Eventos: los de hoy delante, y los **recurrentes**
 * intercalados para que no copen el principio de la lista.
 *
 * Un recurrente es un evento que **dura más de 5 días, o que empezó antes de
 * hoy y dura más de 1** (sigue vivo desde otro día). Lo de «más de 1 día» deja
 * fuera el concierto de anoche que todavía no ha terminado:
 * el mercadillo de todos los domingos o la exposición de enero a diciembre,
 * cargados con una sola fecha de inicio y otra de fin. Por fecha de inicio iban
 * siempre los primeros —empezaron hace meses— y tapaban lo que pasa hoy, que es
 * lo que busca quien abre la pestaña.
 *
 * Así que van en dos colas:
 *  - **normales**, como siempre: lo que antes empieza, primero;
 *  - **recurrentes**, lo que antes termina, primero (el mercadillo que acaba
 *    este mes antes que la exposición que sigue hasta diciembre);
 *
 * y se reparten **5 normales, 1 recurrente, 5 normales, 1 recurrente…** Si se
 * acaba una cola, la otra sigue sin huecos.
 *
 * Se hace en la consulta y no al pintar: la lista va por páginas, y repartir en
 * la app haría que cada página tuviera su propio patrón y que el scroll infinito
 * repitiera o se saltara eventos. Aquí cada evento tiene su puesto fijo y
 * `LIMIT/OFFSET` corta donde toca. Por eso la app no cambia.
 *
 * Los tres números se cambian aquí.
 */
final class EventFeedOrder
{
    /** Normales seguidos antes de cada tanda de recurrentes. */
    public const NORMAL_RUN = 5;

    /** Recurrentes en cada tanda. */
    public const RECURRING_RUN = 1;

    /** Un evento es recurrente si dura **más** de estos días… */
    public const RECURRING_MIN_DAYS = 5;

    /** …o si empezó antes de hoy y dura más de éstos. */
    public const ONGOING_MIN_DAYS = 1;

    /** «Hoy» es el día de Madrid: el feed es de aquí, no de UTC. */
    private const TIMEZONE = 'Europe/Madrid';

    /**
     * El puesto de un evento en la lista (base 0), según su cola y su orden en
     * ella. Es la misma cuenta que hace `orderBy()` en SQL; está aquí para
     * poder probarla.
     */
    public static function position(bool $recurring, int $index): int
    {
        if (!$recurring) {
            return $index + intdiv($index, self::NORMAL_RUN) * self::RECURRING_RUN;
        }

        $run = intdiv($index, self::RECURRING_RUN);

        return ($run + 1) * self::NORMAL_RUN + $run * self::RECURRING_RUN + $index % self::RECURRING_RUN;
    }

    /** El `ORDER BY` de la consulta del feed (alias `geo`). */
    public static function orderBy(): string
    {
        // «Antes de hoy» y no «antes de ahora»: lo que empieza esta mañana es
        // de hoy, y va con los normales.
        $recurring = sprintf(
            "(geo.ended_at - geo.started_at > INTERVAL '%2\$d days'"
            ." OR (geo.started_at < (date_trunc('day', NOW() AT TIME ZONE '%1\$s') AT TIME ZONE '%1\$s')"
            ." AND geo.ended_at - geo.started_at > INTERVAL '%3\$d days'))",
            self::TIMEZONE,
            self::RECURRING_MIN_DAYS,
            self::ONGOING_MIN_DAYS,
        );
        // Orden dentro de cada cola, base 0.
        $index = "(ROW_NUMBER() OVER (
                PARTITION BY {$recurring}
                ORDER BY CASE WHEN {$recurring} THEN geo.ended_at ELSE geo.started_at END, geo.id
            ) - 1)";
        $normal    = self::NORMAL_RUN;
        $recRun    = self::RECURRING_RUN;

        return "CASE WHEN {$recurring}
                THEN ({$index} / {$recRun} + 1) * {$normal} + ({$index} / {$recRun}) * {$recRun} + {$index} % {$recRun}
                ELSE {$index} + ({$index} / {$normal}) * {$recRun}
            END, geo.id";
    }
}
