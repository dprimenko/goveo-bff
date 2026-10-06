<?php

declare(strict_types=1);

namespace App\Tests\Influencers;

use App\Influencers\Domain\InfluencerProfileRules as Rules;
use PHPUnit\Framework\TestCase;

final class InfluencerProfileRulesTest extends TestCase
{
    public function testSocialHandleAcceptsHandlesAndLinks(): void
    {
        self::assertSame('goveo.app', Rules::socialHandle('https://www.instagram.com/goveo.app/'));
        self::assertSame('goveo', Rules::socialHandle('https://www.tiktok.com/@goveo?lang=es'));
        self::assertSame('goveo', Rules::socialHandle('@goveo'));
        self::assertSame('goveo_app', Rules::socialHandle(' goveo_app '));
    }

    public function testSocialHandleEmptyIsNullAndGarbageIsFalse(): void
    {
        self::assertNull(Rules::socialHandle(''));
        self::assertNull(Rules::socialHandle(null));
        self::assertFalse(Rules::socialHandle('no vale!'));
    }

    public function testUsername(): void
    {
        self::assertTrue(Rules::validUsername('creadora.prueba'));
        self::assertFalse(Rules::validUsername('A'));
        self::assertFalse(Rules::validUsername('con espacio'));
    }
}
