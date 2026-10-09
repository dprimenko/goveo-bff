<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Metrics;

use Doctrine\DBAL\Connection;

/**
 * Las cifras de la pantalla «Métricas» del panel: recuentos de nuestra base, sin
 * analítica de terceros.
 *
 * **Tres consultas, todo agregado en Postgres** (`count(*) FILTER`): una por
 * bloque —negocios, usuarios, contenido—. Ni una entidad pasa por memoria, y
 * cargarlas para contarlas en PHP sería leer miles de filas para devolver diez
 * números.
 *
 * Cada bloque va en su propia clave para que añadir otro —el de Google
 * Analytics, cuando llegue— sea una clave más y no reordenar lo que hay. El de
 * instalaciones (`installs`) es una cuarta consulta, sobre su propia tabla.
 */
final class DashboardMetrics
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function at(\DateTimeImmutable $now): array
    {
        $window = MonthWindow::at($now);
        $params = $window->sqlParams();

        return [
            'generated_at' => $window->now->format(DATE_ATOM),
            'timezone'     => MonthWindow::TIMEZONE,
            'period'       => [
                'current'  => [
                    'month' => $window->currentLabel(),
                    'from'  => $window->currentStart->format(DATE_ATOM),
                    'to'    => $window->currentEnd->format(DATE_ATOM),
                ],
                'previous' => [
                    'month' => $window->previousLabel(),
                    'from'  => $window->previousStart->format(DATE_ATOM),
                    'to'    => $window->currentStart->format(DATE_ATOM),
                ],
            ],
            'businesses' => $this->businesses($params),
            'users'      => $this->users($params),
            'content'    => $this->content($params),
            'installs'   => $this->installs($window),
        ];
    }

    /**
     * Paso a la app: instalaciones que Branch atribuye a un enlace, contadas por
     * la propia app en su primera apertura (`POST /public/app-installs`). Ver
     * `AppInstallsReport`.
     *
     * La tabla guarda **días de Madrid**, así que aquí se compara por fecha y no
     * por instante: los límites del mes van como `Y-m-d` locales.
     *
     * @return array<string, mixed>
     */
    private function installs(MonthWindow $window): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT platform, channel, feature, campaign, kind,
                   sum(installs) FILTER (WHERE day >= :cur_day AND day < :next_day) AS current,
                   sum(installs) FILTER (WHERE day < :cur_day) AS previous
              FROM app_installs_daily
             WHERE day >= :prev_day AND day < :next_day
             GROUP BY platform, channel, feature, campaign, kind
            SQL, $window->dayParams());

        return AppInstallsReport::fromRows($rows);
    }

    /**
     * Estados de los negocios no archivados. «Completo» y «activo» se cuentan
     * **sólo sobre los validados**: un pendiente no ha tenido ocasión de subir
     * nada ni de salir en la app, y contarlo sólo bajaría el porcentaje.
     *
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function businesses(array $params): array
    {
        $verified = BusinessStatus::VERIFIED;
        $sql      = sprintf(
            <<<'SQL'
                SELECT count(*) FILTER (WHERE %s) AS pending,
                       count(*) FILTER (WHERE %s) AS awaiting_payment,
                       count(*) FILTER (WHERE %s) AS scraped,
                       count(*) FILTER (WHERE %s) AS verified,
                       count(*) FILTER (WHERE %s AND %s) AS filled,
                       count(*) FILTER (WHERE %s AND %s) AS active_current,
                       count(*) FILTER (WHERE %s AND %s) AS active_previous
                  FROM business b
                 WHERE b.deleted_at IS NULL
                SQL,
            BusinessStatus::PENDING,
            BusinessStatus::AWAITING_PAYMENT,
            BusinessStatus::SCRAPED,
            $verified,
            $verified, BusinessStatus::FILLED,
            $verified, BusinessStatus::activeBetween('cur_from', 'cur_to'),
            $verified, BusinessStatus::activeBetween('prev_from', 'cur_from'),
        );

        $row = $this->db->fetchAssociative($sql, $params) ?: [];

        return [
            'pending'          => self::int($row, 'pending'),
            'awaiting_payment' => self::int($row, 'awaiting_payment'),
            'scraped'          => self::int($row, 'scraped'),
            'verified'         => self::int($row, 'verified'),
            'filled'           => self::int($row, 'filled'),
            'active'           => [
                'current'  => self::int($row, 'active_current'),
                'previous' => self::int($row, 'active_previous'),
            ],
        ];
    }

    /**
     * Cuentas con correo en `users`. Sin correo sólo hay cuentas de sistema (la
     * del scraping, la de la Agenda) o perfiles que lleva el equipo: no son
     * personas registradas.
     *
     * El total, sin las cuentas borradas; las altas del mes, **con** ellas: quien
     * se registró y se fue ese mismo mes también se registró.
     *
     * ⚠️ Quien entra por primera vez con Google o Apple **no deja fila aquí**
     * (sólo existe en Keycloak), así que la cifra se queda corta en esos casos.
     *
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function users(array $params): array
    {
        $row = $this->db->fetchAssociative(<<<'SQL'
            SELECT count(*) FILTER (WHERE u.deleted_at IS NULL) AS total,
                   count(*) FILTER (WHERE u.created_at >= :cur_from AND u.created_at < :cur_to) AS signups_current,
                   count(*) FILTER (WHERE u.created_at >= :prev_from AND u.created_at < :cur_from) AS signups_previous
              FROM users u
             WHERE u.email IS NOT NULL
            SQL, $params) ?: [];

        return [
            'total'   => self::int($row, 'total'),
            'signups' => [
                'current'  => self::int($row, 'signups_current'),
                'previous' => self::int($row, 'signups_previous'),
            ],
        ];
    }

    /**
     * Lo subido en cada mes y los eventos vigentes ahora.
     *
     * - **Vídeos y fotos**: por fecha de alta, de negocios e influencers, sin lo
     *   que importa el scraping y contando lo borrado después, con el mismo
     *   criterio que «activo».
     * - **Productos**: igual, por fecha de alta.
     * - **Eventos vigentes**: los que la app enseña en Eventos y aún no han
     *   terminado — validados, codificados, sin borrar, y fuera los de una sala
     *   del scraping sin validar, que el feed tampoco enseña. «En curso» son los
     *   que ya han empezado.
     *
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function content(array $params): array
    {
        $row = $this->db->fetchAssociative(<<<'SQL'
            SELECT
                (SELECT count(*) FROM geostories g
                  WHERE g.external_ref IS NULL AND g.created_at >= :cur_from AND g.created_at < :cur_to) AS stories_current,
                (SELECT count(*) FROM geostories g
                  WHERE g.external_ref IS NULL AND g.created_at >= :prev_from AND g.created_at < :cur_from) AS stories_previous,
                (SELECT count(*) FROM products p
                  WHERE p.created_at >= :cur_from AND p.created_at < :cur_to) AS products_current,
                (SELECT count(*) FROM products p
                  WHERE p.created_at >= :prev_from AND p.created_at < :cur_from) AS products_previous,
                ev.live   AS events_live,
                ev.ongoing AS events_ongoing
              FROM (
                SELECT count(*) AS live,
                       count(*) FILTER (WHERE g.started_at <= now()) AS ongoing
                  FROM geostories g
                  JOIN categories c ON c.id = g.category_id AND c.slug = 'events'
             LEFT JOIN business buss ON buss.id = g.business_id
                 WHERE g.deleted_at IS NULL
                   AND g.verified_at IS NOT NULL
                   AND g.status = 'ready'
                   AND g.ended_at >= now()
                   AND NOT (buss.external_ref IS NOT NULL AND buss.verified_at IS NULL)
              ) ev
            SQL, $params) ?: [];

        return [
            'geostories' => [
                'current'  => self::int($row, 'stories_current'),
                'previous' => self::int($row, 'stories_previous'),
            ],
            'products' => [
                'current'  => self::int($row, 'products_current'),
                'previous' => self::int($row, 'products_previous'),
            ],
            'events' => [
                'live'    => self::int($row, 'events_live'),
                'ongoing' => self::int($row, 'events_ongoing'),
            ],
        ];
    }

    /** Postgres devuelve los `count` como texto por el driver; aquí salen enteros. */
    private static function int(array $row, string $key): int
    {
        return (int) ($row[$key] ?? 0);
    }
}
