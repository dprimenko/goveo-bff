<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\Discount;
use App\Billing\Domain\PriceQuote;
use App\Billing\Domain\PromoCode;

/**
 * Outcome of looking a code up: either the tariffs it grants with their prices,
 * or why it cannot be used.
 */
final readonly class ResolvedPromoCode
{
    private function __construct(
        public bool $isValid,
        public ?string $reason,
        public ?PromoCode $code,
        public ?Discount $discount,
        /** @var list<PriceQuote> */
        public array $quotes,
    ) {}

    public static function invalid(string $reason): self
    {
        return new self(false, $reason, null, null, []);
    }

    /** @param list<PriceQuote> $quotes */
    public static function valid(PromoCode $code, ?Discount $discount, array $quotes): self
    {
        return new self(true, null, $code, $discount, $quotes);
    }
}
