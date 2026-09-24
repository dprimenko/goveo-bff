<?php

declare(strict_types=1);

namespace App\Tests\Loyalty;

use App\Loyalty\Domain\LoyaltyCard;
use App\Loyalty\Domain\LoyaltyProgram;
use App\Loyalty\Domain\LoyaltyToken;
use App\Loyalty\Domain\LoyaltyTokenKind;
use PHPUnit\Framework\TestCase;

final class LoyaltyRulesTest extends TestCase
{
    public function testTheSixthStampHasNoEffect(): void
    {
        $card = new LoyaltyCard('tarjeta-1', 'usuario-1', 'negocio-1');
        for ($i = 0; $i < LoyaltyCard::MAX_STAMPS; $i++) {
            self::assertTrue($card->addStamp());
        }

        self::assertFalse($card->addStamp());
        self::assertSame(5, $card->getStamps());
    }

    public function testOnlyStagesThreeAndFiveGiveRewards(): void
    {
        $program = new LoyaltyProgram('negocio-1');

        $this->expectException(\InvalidArgumentException::class);
        $program->setReward(4, 'Algo');
    }

    public function testAnEmptyRewardRemovesIt(): void
    {
        $program = new LoyaltyProgram('negocio-1');
        $program->setReward(3, 'Café gratis');
        $program->setReward(3, '   ');

        self::assertFalse($program->hasRewards());
    }

    public function testRemovingTheNameRemovesTheDescriptionToo(): void
    {
        $program = new LoyaltyProgram('negocio-1');
        $program->setReward(5, 'Postre + vermut', 'Cualquier postre de la carta');
        self::assertSame('Cualquier postre de la carta', $program->rewardDescription(5));

        $program->setReward(5, '', 'Cualquier postre de la carta');

        self::assertNull($program->rewardDescription(5));
        self::assertFalse($program->hasRewards());
    }

    public function testARedeemQrMustSayWhichReward(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LoyaltyToken::issue('qr-1', 'negocio-1', LoyaltyTokenKind::Redeem, null, 'gestor-1');
    }

    public function testTheQrLastsHalfAnHour(): void
    {
        $issuedAt = new \DateTimeImmutable('2026-09-24 12:00:00');
        $token = LoyaltyToken::issue('qr-1', 'negocio-1', LoyaltyTokenKind::Stamp, null, 'gestor-1', $issuedAt)['token'];

        self::assertFalse($token->isExpired(new \DateTimeImmutable('2026-09-24 12:29:00')));
        self::assertTrue($token->isExpired(new \DateTimeImmutable('2026-09-24 12:30:00')));
    }
}
