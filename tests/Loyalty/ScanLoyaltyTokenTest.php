<?php

declare(strict_types=1);

namespace App\Tests\Loyalty;

use App\Loyalty\Application\LoyaltyAvailability;
use App\Loyalty\Application\ScanLoyaltyToken;
use App\Loyalty\Application\ScanOutcome;
use App\Loyalty\Domain\LoyaltyCard;
use App\Loyalty\Domain\LoyaltyProgram;
use App\Loyalty\Domain\LoyaltyToken;
use App\Loyalty\Domain\LoyaltyTokenKind;
use PHPUnit\Framework\TestCase;

/**
 * Lo que pasa al escanear un QR de la tarjeta.
 *
 * Lo que más importa: el QR **sólo se gasta si hace efecto**. Si el cliente no
 * puede sumar o no llega al premio, el negocio tiene que poder usar ese mismo
 * QR con el siguiente.
 */
final class ScanLoyaltyTokenTest extends TestCase
{
    private const BUSINESS = 'negocio-1';
    private const USER = 'usuario-1';

    private InMemoryLoyalty $store;
    private ScanLoyaltyToken $scanner;

    protected function setUp(): void
    {
        $this->store = new InMemoryLoyalty();
        $this->store->eligible = [self::BUSINESS];

        $program = new LoyaltyProgram(self::BUSINESS);
        $program->setReward(3, 'Café gratis');
        $program->setReward(5, 'Postre + vermut');
        $this->store->programs[self::BUSINESS] = $program;

        $this->scanner = new ScanLoyaltyToken(
            $this->store,
            $this->store,
            $this->store,
            $this->store,
            new LoyaltyAvailability($this->store),
        );
    }

    public function testTheFirstScanCreatesTheCardWithOneStamp(): void
    {
        $result = $this->scanner->scan($this->stampToken(), self::USER);

        self::assertSame(ScanOutcome::Stamped, $result->outcome);
        self::assertSame(1, $result->stamps);
        self::assertSame(self::BUSINESS, $result->businessId);
        self::assertCount(1, $this->store->events);
    }

    public function testAFullCardDoesNotStampAndLeavesTheQrUnused(): void
    {
        $this->cardWith(5);
        $plain = $this->stampToken();

        $result = $this->scanner->scan($plain, self::USER);

        self::assertSame(ScanOutcome::CardFull, $result->outcome);
        self::assertSame(5, $result->stamps);
        self::assertFalse($this->store->tokens[$plain]->isUsed());
        self::assertSame([], $this->store->events);
    }

    public function testRedeemingTheSmallRewardResetsTheCard(): void
    {
        $this->cardWith(4);

        $result = $this->scanner->scan($this->redeemToken(3), self::USER);

        self::assertSame(ScanOutcome::Redeemed, $result->outcome);
        self::assertSame(0, $result->stamps);
        self::assertSame('Café gratis', $result->rewardLabel);
        self::assertSame('Café gratis', $this->store->events[0]->getRewardLabel());
    }

    public function testRedeemingWithoutEnoughStampsLeavesTheQrUnused(): void
    {
        $this->cardWith(3);
        $plain = $this->redeemToken(5);

        $result = $this->scanner->scan($plain, self::USER);

        self::assertSame(ScanOutcome::NotEnoughStamps, $result->outcome);
        self::assertSame(3, $result->stamps);
        self::assertFalse($this->store->tokens[$plain]->isUsed());
    }

    public function testRedeemingWithoutACardIsNotEnoughStamps(): void
    {
        $result = $this->scanner->scan($this->redeemToken(3), self::USER);

        self::assertSame(ScanOutcome::NotEnoughStamps, $result->outcome);
        self::assertSame([], $this->store->cards);
    }

    public function testARewardRemovedAfterIssuingTheQrCannotBeRedeemed(): void
    {
        $this->cardWith(5);
        $plain = $this->redeemToken(3);
        $this->store->programs[self::BUSINESS]->setReward(3, null);

        self::assertSame(ScanOutcome::RewardUnavailable, $this->scanner->scan($plain, self::USER)->outcome);
    }

    public function testTheSameUserScanningTwiceIsNotAnError(): void
    {
        // El enlace llega dos veces: el escáner y Branch al abrir la app.
        $plain = $this->stampToken();
        $this->scanner->scan($plain, self::USER);

        $result = $this->scanner->scan($plain, self::USER);

        self::assertSame(ScanOutcome::AlreadyApplied, $result->outcome);
        self::assertTrue($result->outcome->isSuccess());
        self::assertSame(1, $result->stamps);
    }

    public function testAnotherUserCannotReuseAQr(): void
    {
        $plain = $this->stampToken();
        $this->scanner->scan($plain, self::USER);

        $result = $this->scanner->scan($plain, 'usuario-2');

        self::assertSame(ScanOutcome::AlreadyUsed, $result->outcome);
        self::assertNull($this->store->find('usuario-2', self::BUSINESS));
    }

    public function testAnExpiredQrDoesNothing(): void
    {
        $plain = $this->stampToken(new \DateTimeImmutable('-31 minutes'));

        self::assertSame(ScanOutcome::Expired, $this->scanner->scan($plain, self::USER)->outcome);
        self::assertSame([], $this->store->cards);
    }

    public function testAnUnknownQrIsInvalid(): void
    {
        $result = $this->scanner->scan('no-existe', self::USER);

        self::assertSame(ScanOutcome::Invalid, $result->outcome);
        self::assertNull($result->businessId);
    }

    public function testABusinessThatLostItsTariffCannotStamp(): void
    {
        $this->store->eligible = [];

        self::assertSame(ScanOutcome::Unavailable, $this->scanner->scan($this->stampToken(), self::USER)->outcome);
    }

    public function testManualActivationGivesTheCardWithoutTheTariff(): void
    {
        $this->store->eligible = [];
        $this->store->programs[self::BUSINESS]->setManuallyEnabled(true);

        self::assertSame(ScanOutcome::Stamped, $this->scanner->scan($this->stampToken(), self::USER)->outcome);
    }

    public function testWithoutRewardsThereIsNoCard(): void
    {
        $this->store->programs[self::BUSINESS]->setReward(3, '');
        $this->store->programs[self::BUSINESS]->setReward(5, null);

        self::assertSame(ScanOutcome::Unavailable, $this->scanner->scan($this->stampToken(), self::USER)->outcome);
    }

    private function cardWith(int $stamps): void
    {
        $card = new LoyaltyCard('tarjeta-1', self::USER, self::BUSINESS);
        for ($i = 0; $i < $stamps; $i++) {
            $card->addStamp();
        }
        $this->store->save($card);
    }

    private function stampToken(?\DateTimeImmutable $issuedAt = null): string
    {
        return $this->issue(LoyaltyTokenKind::Stamp, null, $issuedAt);
    }

    private function redeemToken(int $stage): string
    {
        return $this->issue(LoyaltyTokenKind::Redeem, $stage);
    }

    private function issue(LoyaltyTokenKind $kind, ?int $stage, ?\DateTimeImmutable $issuedAt = null): string
    {
        $issued = LoyaltyToken::issue(
            'qr-'.count($this->store->tokens),
            self::BUSINESS,
            $kind,
            $stage,
            'gestor-1',
            $issuedAt,
        );
        $this->store->tokens[$issued['plain']] = $issued['token'];

        return $issued['plain'];
    }
}
