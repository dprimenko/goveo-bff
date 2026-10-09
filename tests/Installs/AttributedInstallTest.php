<?php

declare(strict_types=1);

namespace App\Tests\Installs;

use App\Installs\Domain\AttributedInstall;
use PHPUnit\Framework\TestCase;

/**
 * Lo que entra por `POST /public/app-installs` acaba siendo una fila: aquí se
 * fija que sólo pasa lo que se puede contar y que nada se cuela tal cual.
 */
final class AttributedInstallTest extends TestCase
{
    public function testKeepsWhatOurLinksSend(): void
    {
        $install = AttributedInstall::fromPayload([
            'platform' => 'ios', 'channel' => 'web', 'feature' => 'web_to_app',
            'campaign' => 'mobile_menu', 'kind' => 'app',
        ]);

        self::assertNotNull($install);
        self::assertSame(
            ['ios', 'web', 'web_to_app', 'mobile_menu', 'app'],
            [$install->platform, $install->channel, $install->feature, $install->campaign, $install->kind],
        );
    }

    public function testWithoutAKnownPlatformThereIsNothingToCount(): void
    {
        self::assertNull(AttributedInstall::fromPayload([]));
        self::assertNull(AttributedInstall::fromPayload(['platform' => 'windows']));
        self::assertNull(AttributedInstall::fromPayload(['platform' => ['ios']]));
    }

    public function testClosedListsFallBackToOther(): void
    {
        $install = AttributedInstall::fromPayload([
            'platform' => 'Android', 'feature' => 'hack<script>', 'kind' => 'zzz',
        ]);

        self::assertSame('android', $install?->platform);
        self::assertSame(AttributedInstall::OTHER, $install->feature);
        self::assertSame(AttributedInstall::OTHER, $install->kind);
    }

    public function testMissingValuesAreEmptyAndAMissingKindIsNone(): void
    {
        $install = AttributedInstall::fromPayload(['platform' => 'ios', 'channel' => null, 'campaign' => 42]);

        self::assertSame('', $install?->channel);
        self::assertSame('', $install->feature);
        self::assertSame('42', $install->campaign);
        self::assertSame('none', $install->kind);
    }

    public function testFreeTextIsSluggedAndCut(): void
    {
        $install = AttributedInstall::fromPayload([
            'platform' => 'ios',
            'channel'  => '  Instagram Stories!!  ',
            'campaign' => str_repeat('verano ', 30),
        ]);

        self::assertSame('instagram_stories', $install?->channel);
        self::assertLessThanOrEqual(AttributedInstall::MAX_CAMPAIGN, strlen($install->campaign));
        self::assertMatchesRegularExpression('/^[a-z0-9_-]+$/', $install->campaign);
    }

    public function testCollapsedKeepsTheClosedListsAndHidesTheFreeText(): void
    {
        $install = AttributedInstall::fromPayload([
            'platform' => 'ios', 'channel' => 'spam1', 'feature' => 'store', 'campaign' => 'spam2', 'kind' => 'business',
        ])?->collapsed();

        self::assertSame(
            ['ios', 'other', 'store', 'other', 'business'],
            [$install?->platform, $install->channel, $install->feature, $install->campaign, $install->kind],
        );
    }
}
