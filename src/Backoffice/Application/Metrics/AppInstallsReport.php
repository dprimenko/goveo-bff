<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Metrics;

/**
 * El bloque «Paso a la app» de las métricas, a partir de las filas de
 * `app_installs_daily` ya sumadas por combinación y mes.
 *
 * Una sola consulta trae las combinaciones de los dos meses (son unas decenas)
 * y los desgloses se hacen aquí: cuatro `GROUP BY` distintos serían cuatro
 * vueltas a la base para el mismo puñado de filas.
 *
 * - **`total`**: instalaciones atribuidas por Branch. Las que no pasan por un
 *   enlace —buscar Goveo en la tienda— no llegan nunca al BFF.
 * - **`from_web`**: las de `channel = web`, los enlaces que reparte goveo.app
 *   (botones de descarga y fichas compartidas desde la web).
 * - **`by_platform`**, **`by_kind`** (a qué contenido llevaba el enlace) y
 *   **`by_feature`** (qué tipo de enlace era: compartir desde la app, botón de
 *   descarga de la web…).
 * - **`web_by_campaign`**: de las que vienen de la web, qué botón (`campaign`
 *   es el sitio del botón, ver `goveo-astro/src/services/app-download/`).
 *
 * Cada desglose va de más a menos en el mes en curso.
 */
final class AppInstallsReport
{
    /**
     * @param list<array<string, mixed>> $rows `platform, channel, feature, campaign, kind, current, previous`
     *
     * @return array<string, mixed>
     */
    public static function fromRows(array $rows): array
    {
        $total = ['current' => 0, 'previous' => 0];
        $web   = ['current' => 0, 'previous' => 0];
        $by    = ['platform' => [], 'kind' => [], 'feature' => [], 'web_campaign' => []];

        foreach ($rows as $row) {
            $current  = (int) ($row['current'] ?? 0);
            $previous = (int) ($row['previous'] ?? 0);

            $total['current']  += $current;
            $total['previous'] += $previous;

            self::add($by['platform'], (string) ($row['platform'] ?? ''), $current, $previous);
            self::add($by['kind'], (string) ($row['kind'] ?? ''), $current, $previous);
            self::add($by['feature'], (string) ($row['feature'] ?? ''), $current, $previous);

            if (($row['channel'] ?? '') === 'web') {
                $web['current']  += $current;
                $web['previous'] += $previous;
                self::add($by['web_campaign'], (string) ($row['campaign'] ?? ''), $current, $previous);
            }
        }

        return [
            'total'           => $total,
            'from_web'        => $web,
            'by_platform'     => self::list($by['platform'], 'platform'),
            'by_kind'         => self::list($by['kind'], 'kind'),
            'by_feature'      => self::list($by['feature'], 'feature'),
            'web_by_campaign' => self::list($by['web_campaign'], 'campaign'),
        ];
    }

    /** @param array<string, array{current: int, previous: int}> $bucket */
    private static function add(array &$bucket, string $key, int $current, int $previous): void
    {
        $bucket[$key] ??= ['current' => 0, 'previous' => 0];
        $bucket[$key]['current']  += $current;
        $bucket[$key]['previous'] += $previous;
    }

    /**
     * @param array<string, array{current: int, previous: int}> $bucket
     *
     * @return list<array<string, int|string>>
     */
    private static function list(array $bucket, string $name): array
    {
        $out = [];
        foreach ($bucket as $key => $counts) {
            $out[] = [$name => (string) $key] + $counts;
        }

        usort($out, static fn (array $a, array $b) => [$b['current'], $b['previous']] <=> [$a['current'], $a['previous']]);

        return $out;
    }
}
