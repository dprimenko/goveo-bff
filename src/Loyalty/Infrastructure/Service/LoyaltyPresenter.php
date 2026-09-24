<?php

declare(strict_types=1);

namespace App\Loyalty\Infrastructure\Service;

use App\Business\Domain\Business;
use App\Loyalty\Application\LoyaltyStatus;
use App\Loyalty\Domain\LoyaltyCard;
use App\Loyalty\Domain\LoyaltyProgram;

/** La forma en que la API devuelve una tarjeta, igual en todos los endpoints. */
final class LoyaltyPresenter
{
    /**
     * @param bool $forUser false en la vista pública: sin usuario no se sabe si
     *                      llega a los premios, y `redeemable` no se manda
     *
     * @return array<string, mixed>
     */
    public function card(
        Business $business,
        ?LoyaltyProgram $program,
        LoyaltyStatus $status,
        ?LoyaltyCard $card,
        bool $forUser = true,
    ): array {
        $stamps = $card?->getStamps() ?? 0;

        return [
            'business' => [
                'id'     => $business->getId(),
                'slug'   => $business->getSlug(),
                'name'   => $business->getName(),
                'avatar' => $business->getAvatar(),
            ],
            'available'  => $status->isAvailable(),
            'max_stamps' => LoyaltyCard::MAX_STAMPS,
            'stamps'     => $stamps,
            'rewards'    => $status->isAvailable() ? $this->rewards($program, $forUser ? $stamps : null) : [],
        ];
    }

    /**
     * `redeemable` sólo con los sellos del usuario: sin ellos (la vista pública)
     * no se sabe, y se omite.
     *
     * @return list<array{stage: int, label: string, description: ?string, redeemable?: bool}>
     */
    public function rewards(?LoyaltyProgram $program, ?int $stamps = null): array
    {
        $rewards = [];
        foreach ($program?->rewards() ?? [] as $stage => $reward) {
            $item = ['stage' => $stage, 'label' => $reward['label'], 'description' => $reward['description']];
            if ($stamps !== null) {
                $item['redeemable'] = $stamps >= $stage;
            }
            $rewards[] = $item;
        }

        return $rewards;
    }
}
