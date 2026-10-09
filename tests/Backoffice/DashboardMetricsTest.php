<?php

declare(strict_types=1);

namespace App\Tests\Backoffice;

use App\Backoffice\Application\Metrics\BusinessStatus;
use App\Backoffice\Application\Metrics\DashboardMetrics;
use App\Backoffice\Application\Metrics\MonthWindow;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * La forma de la respuesta de `/api/admin/metrics` y de qué se alimenta.
 *
 * Las consultas se prueban contra la base local a mano (ver «Métricas» en
 * CLAUDE.md); aquí se fija lo que no depende de los datos: que se cuenta en
 * Postgres con los límites del mes de Madrid, que «pendiente» es la misma
 * condición que la cola de revisión y que lo que llega como texto sale como
 * número.
 */
final class DashboardMetricsTest extends TestCase
{
    public function testBuildsTheReportFromThreeAggregateQueries(): void
    {
        $now     = new \DateTimeImmutable('2026-09-29T15:38:00Z');
        $params  = MonthWindow::at($now)->sqlParams();
        $queries = [];

        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(3))
            ->method('fetchAssociative')
            ->willReturnCallback(static function (string $sql, array $bound) use (&$queries, $params) {
                self::assertSame($params, $bound);
                $queries[] = $sql;

                // Postgres devuelve los `count` como texto.
                return match (count($queries)) {
                    1 => [
                        'pending' => '3', 'awaiting_payment' => '1', 'scraped' => '7',
                        'verified' => '454', 'filled' => '338',
                        'active_current' => '12', 'active_previous' => '20',
                    ],
                    2 => ['total' => '1366', 'signups_current' => '34', 'signups_previous' => '4'],
                    3 => [
                        'stories_current' => '7', 'stories_previous' => '25',
                        'products_current' => '14', 'products_previous' => '130',
                        'events_live' => '3', 'events_ongoing' => '1',
                    ],
                };
            });

        // Paso a la app: las combinaciones de los dos meses, por día de Madrid.
        $db->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql, array $bound) {
                self::assertStringContainsString('app_installs_daily', $sql);
                self::assertSame(['prev_day' => '2026-08-01', 'cur_day' => '2026-09-01', 'next_day' => '2026-10-01'], $bound);

                return [['platform' => 'ios', 'channel' => 'web', 'feature' => 'web_to_app', 'campaign' => 'story', 'kind' => 'app', 'current' => '3', 'previous' => '1']];
            });

        $report = (new DashboardMetrics($db))->at($now);

        self::assertSame('Europe/Madrid', $report['timezone']);
        self::assertSame('2026-09', $report['period']['current']['month']);
        self::assertSame('2026-08-01T00:00:00+02:00', $report['period']['previous']['from']);
        self::assertSame('2026-09-01T00:00:00+02:00', $report['period']['previous']['to']);

        self::assertSame([
            'pending'          => 3,
            'awaiting_payment' => 1,
            'scraped'          => 7,
            'verified'         => 454,
            'filled'           => 338,
            'active'           => ['current' => 12, 'previous' => 20],
        ], $report['businesses']);
        self::assertSame(['total' => 1366, 'signups' => ['current' => 34, 'previous' => 4]], $report['users']);
        self::assertSame([
            'geostories' => ['current' => 7, 'previous' => 25],
            'products'   => ['current' => 14, 'previous' => 130],
            'events'     => ['live' => 3, 'ongoing' => 1],
        ], $report['content']);

        self::assertSame(['current' => 3, 'previous' => 1], $report['installs']['total']);
        self::assertSame(['current' => 3, 'previous' => 1], $report['installs']['from_web']);

        // «Pendiente» es la condición de la cola, no una parecida.
        self::assertStringContainsString(BusinessStatus::PENDING, $queries[0]);
        self::assertStringContainsString(BusinessStatus::FILLED, $queries[0]);
    }

    public function testAnEmptyResultIsZeroNotAnError(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn(false);

        $report = (new DashboardMetrics($db))->at(new \DateTimeImmutable());

        self::assertSame(0, $report['businesses']['pending']);
        self::assertSame(['current' => 0, 'previous' => 0], $report['users']['signups']);
        self::assertSame(['live' => 0, 'ongoing' => 0], $report['content']['events']);
        self::assertSame(['current' => 0, 'previous' => 0], $report['installs']['total']);
    }

    public function testActivityWindowsDoNotOverlap(): void
    {
        // El mes anterior acaba justo donde empieza el en curso: un vídeo subido
        // en el primer segundo de septiembre es de septiembre y sólo de él.
        self::assertStringContainsString(
            ':prev_from AND ag.created_at < :cur_from',
            BusinessStatus::activeBetween('prev_from', 'cur_from'),
        );
        self::assertStringContainsString('external_ref IS NULL', BusinessStatus::activeBetween('a', 'b'));
    }
}
