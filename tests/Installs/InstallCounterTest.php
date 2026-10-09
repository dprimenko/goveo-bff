<?php

declare(strict_types=1);

namespace App\Tests\Installs;

use App\Installs\Application\InstallCounter;
use App\Installs\Domain\AttributedInstall;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * El recuento: una sentencia que suma uno, el día de Madrid y el freno de
 * combinaciones por día.
 */
final class InstallCounterTest extends TestCase
{
    private const PAYLOAD = [
        'platform' => 'android', 'channel' => 'web', 'feature' => 'web_to_app', 'campaign' => 'story', 'kind' => 'app',
    ];

    public function testAddsOneOnTheMadridDay(): void
    {
        $bound = $this->record(exists: false, rowsToday: 3);

        // Las 23:30 UTC del 30 de septiembre ya son el 1 de octubre en Madrid.
        self::assertSame('2026-10-01', $bound['day']);
        self::assertSame('web', $bound['channel']);
        self::assertSame('story', $bound['campaign']);
    }

    public function testANewCombinationOnAFullDayGoesToOther(): void
    {
        $bound = $this->record(exists: false, rowsToday: InstallCounter::MAX_ROWS_PER_DAY);

        self::assertSame(AttributedInstall::OTHER, $bound['channel']);
        self::assertSame(AttributedInstall::OTHER, $bound['campaign']);
        self::assertSame('web_to_app', $bound['feature']);
    }

    public function testAKnownCombinationStillCountsOnAFullDay(): void
    {
        $bound = $this->record(exists: true, rowsToday: InstallCounter::MAX_ROWS_PER_DAY);

        self::assertSame('story', $bound['campaign']);
    }

    /** @return array<string, string> lo que se pasa al `INSERT` */
    private function record(bool $exists, int $rowsToday): array
    {
        $bound = [];

        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')->willReturnCallback(
            static fn (string $sql) => str_contains($sql, 'count(*)') ? (string) $rowsToday : ($exists ? 1 : false),
        );
        $db->expects(self::once())
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params) use (&$bound) {
                self::assertStringContainsString('ON CONFLICT', $sql);
                self::assertStringContainsString('installs + 1', $sql);
                $bound = $params;

                return 1;
            });

        $install = AttributedInstall::fromPayload(self::PAYLOAD);
        self::assertNotNull($install);

        (new InstallCounter($db))->record($install, new \DateTimeImmutable('2026-09-30T23:30:00Z'));

        return $bound;
    }
}
