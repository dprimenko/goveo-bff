<?php

declare(strict_types=1);

namespace App\Loyalty\Application;

use App\Loyalty\Domain\LoyaltyCard;
use App\Loyalty\Domain\LoyaltyCardRepository;
use App\Loyalty\Domain\LoyaltyEvent;
use App\Loyalty\Domain\LoyaltyEventRepository;
use App\Loyalty\Domain\LoyaltyProgramRepository;
use App\Loyalty\Domain\LoyaltyToken;
use App\Loyalty\Domain\LoyaltyTokenKind;
use App\Loyalty\Domain\LoyaltyTokenRepository;
use App\Shared\Domain\UuidGenerator;

/**
 * Un usuario escanea el QR de un negocio: suma un sello o canjea un premio.
 *
 * El QR **sólo se gasta si hace efecto**. Con la tarjeta llena, o sin sellos
 * suficientes para el premio, se le explica al cliente y el negocio puede usar
 * ese mismo QR con el siguiente.
 *
 * Quien lo llama tiene que envolverlo en una transacción: gastar el QR, mover
 * los sellos y apuntarlo en el historial van juntos.
 */
final class ScanLoyaltyToken
{
    public function __construct(
        private readonly LoyaltyTokenRepository $tokens,
        private readonly LoyaltyCardRepository $cards,
        private readonly LoyaltyProgramRepository $programs,
        private readonly LoyaltyEventRepository $events,
        private readonly LoyaltyAvailability $availability,
    ) {}

    public function scan(string $plainToken, string $userId): ScanResult
    {
        $token = $this->tokens->findByPlainToken($plainToken);
        if ($token === null) {
            return new ScanResult(ScanOutcome::Invalid);
        }

        $businessId = $token->getBusinessId();
        $card       = $this->cards->find($userId, $businessId);

        if ($token->isUsed()) {
            return new ScanResult(
                $token->getUsedByUserId() === $userId ? ScanOutcome::AlreadyApplied : ScanOutcome::AlreadyUsed,
                $businessId,
                $card?->getStamps() ?? 0,
            );
        }

        if ($token->isExpired()) {
            return new ScanResult(ScanOutcome::Expired, $businessId, $card?->getStamps() ?? 0);
        }

        $program = $this->programs->findByBusinessId($businessId);
        if (!$this->availability->check($businessId, $program)->isAvailable()) {
            return new ScanResult(ScanOutcome::Unavailable, $businessId, $card?->getStamps() ?? 0);
        }

        $card ??= new LoyaltyCard(UuidGenerator::generate(), $userId, $businessId);

        return $token->getKind() === LoyaltyTokenKind::Stamp
            ? $this->stamp($token, $card, $userId)
            : $this->redeem($token, $card, $userId, $program->rewardFor((int) $token->getRewardStage()));
    }

    private function stamp(LoyaltyToken $token, LoyaltyCard $card, string $userId): ScanResult
    {
        $businessId = $token->getBusinessId();

        if ($card->isFull()) {
            return new ScanResult(ScanOutcome::CardFull, $businessId, $card->getStamps());
        }

        if (!$this->tokens->claim($token, $userId)) {
            return new ScanResult(ScanOutcome::AlreadyUsed, $businessId, $card->getStamps());
        }

        $card->addStamp();
        $this->cards->save($card);
        $this->events->save(new LoyaltyEvent(UuidGenerator::generate(), $card, $token));

        return new ScanResult(ScanOutcome::Stamped, $businessId, $card->getStamps());
    }

    private function redeem(LoyaltyToken $token, LoyaltyCard $card, string $userId, ?string $reward): ScanResult
    {
        $businessId = $token->getBusinessId();
        $stage      = (int) $token->getRewardStage();

        if ($reward === null) {
            return new ScanResult(ScanOutcome::RewardUnavailable, $businessId, $card->getStamps(), $stage);
        }

        if (!$card->canRedeem($stage)) {
            return new ScanResult(ScanOutcome::NotEnoughStamps, $businessId, $card->getStamps(), $stage, $reward);
        }

        if (!$this->tokens->claim($token, $userId)) {
            return new ScanResult(ScanOutcome::AlreadyUsed, $businessId, $card->getStamps());
        }

        $card->redeem($stage);
        $this->cards->save($card);
        $this->events->save(new LoyaltyEvent(UuidGenerator::generate(), $card, $token, $reward));

        return new ScanResult(ScanOutcome::Redeemed, $businessId, $card->getStamps(), $stage, $reward);
    }
}
