<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Controller;

use App\Billing\Application\PromoCodeResolver;
use App\Billing\Domain\PriceQuote;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Resolves a registration code into the tariffs it unlocks and their prices.
 *
 * The app renders what comes back verbatim — it never computes a price itself.
 */
#[Route('/api/registration', name: 'registration_')]
class ValidatePromoCodeController
{
    public function __construct(
        private readonly PromoCodeResolver $resolver,
    ) {}

    #[Route('/promo-codes/validate', name: 'validate_promo_code', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        $code    = is_array($payload) ? trim((string) ($payload['code'] ?? '')) : '';

        if ($code === '') {
            return new JsonResponse(
                ['valid' => false, 'reason' => 'empty'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $resolved = $this->resolver->resolve($code);

        if (!$resolved->isValid) {
            return new JsonResponse(
                ['valid' => false, 'reason' => $resolved->reason],
                Response::HTTP_NOT_FOUND,
            );
        }

        $promo = $resolved->code;
        $plans = array_map(static fn (PriceQuote $q) => $q->toArray(), $resolved->quotes);

        return new JsonResponse([
            'valid' => true,
            'code'  => [
                'code'          => $promo->getCode(),
                'label'         => $promo->getLabel(),
                'unlocks_plans' => $promo->unlocksPlans(),
                'has_discount'  => $promo->hasDiscount(),
                'billed_externally' => $promo->isBilledExternally(),
                'partner_id'    => $promo->getPartnerId(),
            ],
            'discount' => $resolved->discount === null ? null : [
                'name'               => $resolved->discount->getName(),
                'percent_off'        => $resolved->discount->getPercentOff(),
                'amount_off_cents'   => $resolved->discount->getAmountOffCents(),
                'duration'           => $resolved->discount->getDuration()->value,
                'duration_in_months' => $resolved->discount->getDurationInMonths(),
            ],
            // One entry per unlocked tariff. Codes that only carry a discount
            // return an empty list: the plan is whichever the user already picked.
            'plans' => $plans,
        ]);
    }
}
