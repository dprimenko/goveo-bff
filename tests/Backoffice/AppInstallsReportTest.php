<?php

declare(strict_types=1);

namespace App\Tests\Backoffice;

use App\Backoffice\Application\Metrics\AppInstallsReport;
use PHPUnit\Framework\TestCase;

/**
 * Los desgloses de «Paso a la app» a partir de las combinaciones del mes.
 */
final class AppInstallsReportTest extends TestCase
{
    public function testSumsAndBreaksDown(): void
    {
        // Postgres devuelve las sumas como texto, y `null` en un mes sin filas.
        $report = AppInstallsReport::fromRows([
            ['platform' => 'ios', 'channel' => 'web', 'feature' => 'web_to_app', 'campaign' => 'mobile_menu', 'kind' => 'app', 'current' => '4', 'previous' => '1'],
            ['platform' => 'android', 'channel' => 'web', 'feature' => 'store', 'campaign' => 'business_profile', 'kind' => 'business', 'current' => '2', 'previous' => null],
            ['platform' => 'android', 'channel' => 'app', 'feature' => 'video', 'campaign' => '', 'kind' => 'geostory', 'current' => '5', 'previous' => '7'],
        ]);

        self::assertSame(['current' => 11, 'previous' => 8], $report['total']);
        self::assertSame(['current' => 6, 'previous' => 1], $report['from_web']);

        self::assertSame([
            ['platform' => 'android', 'current' => 7, 'previous' => 7],
            ['platform' => 'ios', 'current' => 4, 'previous' => 1],
        ], $report['by_platform']);

        self::assertSame('geostory', $report['by_kind'][0]['kind']);
        self::assertSame(['video', 'web_to_app', 'store'], array_column($report['by_feature'], 'feature'));

        // Sólo lo que viene de la web, por el botón que lo trajo.
        self::assertSame([
            ['campaign' => 'mobile_menu', 'current' => 4, 'previous' => 1],
            ['campaign' => 'business_profile', 'current' => 2, 'previous' => 0],
        ], $report['web_by_campaign']);
    }

    public function testNoRowsIsZeros(): void
    {
        $report = AppInstallsReport::fromRows([]);

        self::assertSame(['current' => 0, 'previous' => 0], $report['total']);
        self::assertSame([], $report['by_platform']);
        self::assertSame([], $report['web_by_campaign']);
    }
}
