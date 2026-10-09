<?php

declare(strict_types=1);

namespace App\Installs\Application;

use App\Installs\Domain\AttributedInstall;
use Doctrine\DBAL\Connection;

/**
 * Suma una instalación atribuida al recuento del día.
 *
 * **Un `INSERT … ON CONFLICT DO UPDATE`**: la primera de una combinación crea
 * la fila y las siguientes le suman uno, en una sola sentencia y sin carreras.
 *
 * **El freno**: la ruta es pública y sin sesión, así que cualquiera puede
 * mandar instalaciones inventadas. No se puede impedir del todo —no hay nada
 * que identificar, y es lo que se quiere—, pero sí que llene la tabla: cuando
 * un día ya tiene `MAX_ROWS_PER_DAY` combinaciones, lo que llegue con una nueva
 * se guarda con canal y campaña en `other` (las listas cerradas ya acotan el
 * resto). Así la tabla crece como mucho unos cientos de filas al día pase lo
 * que pase. Inflar los números sí se puede; se notaría como un pico de
 * `other` o de una campaña que no existe.
 *
 * El BFF no tiene limitador de peticiones (no está `symfony/rate-limiter`);
 * si hiciera falta, iría en nginx por IP sin guardarla.
 */
final class InstallCounter
{
    public const TIMEZONE = 'Europe/Madrid';

    /** Las nuestras son unas decenas al día; doscientas ya es algo raro. */
    public const MAX_ROWS_PER_DAY = 200;

    public function __construct(
        private readonly Connection $db,
    ) {}

    public function record(AttributedInstall $install, \DateTimeImmutable $now): void
    {
        $day = $now->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d');

        if (!$this->exists($day, $install) && $this->rowsOn($day) >= self::MAX_ROWS_PER_DAY) {
            $install = $install->collapsed();
        }

        $this->db->executeStatement(<<<'SQL'
            INSERT INTO app_installs_daily (day, platform, channel, feature, campaign, kind, installs)
            VALUES (:day, :platform, :channel, :feature, :campaign, :kind, 1)
            ON CONFLICT (day, platform, channel, feature, campaign, kind)
            DO UPDATE SET installs = app_installs_daily.installs + 1
            SQL, ['day' => $day] + self::key($install));
    }

    private function exists(string $day, AttributedInstall $install): bool
    {
        return (bool) $this->db->fetchOne(<<<'SQL'
            SELECT 1 FROM app_installs_daily
             WHERE day = :day AND platform = :platform AND channel = :channel
               AND feature = :feature AND campaign = :campaign AND kind = :kind
            SQL, ['day' => $day] + self::key($install));
    }

    private function rowsOn(string $day): int
    {
        return (int) $this->db->fetchOne('SELECT count(*) FROM app_installs_daily WHERE day = :day', ['day' => $day]);
    }

    /** @return array<string, string> */
    private static function key(AttributedInstall $install): array
    {
        return [
            'platform' => $install->platform,
            'channel'  => $install->channel,
            'feature'  => $install->feature,
            'campaign' => $install->campaign,
            'kind'     => $install->kind,
        ];
    }
}
