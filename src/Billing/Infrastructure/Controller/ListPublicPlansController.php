<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Controller;

use App\Billing\Domain\BillingPlan;
use App\Billing\Domain\BillingPlanRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tarifas que el formulario de alta puede ofrecer.
 *
 * Público: quien llega desde la landing sin tarifa elegida tiene que poder ver
 * la lista antes de tener cuenta.
 *
 * Devuelve el `code` legible (`platinum-anual`) además del id, porque es lo que
 * viaja en los enlaces y lo que el formulario recibe por query.
 */
#[Route('/public/billing/plans', name: 'public_billing_plans', methods: ['GET'])]
class ListPublicPlansController
{
    public function __construct(
        private readonly BillingPlanRepository $plans,
    ) {}

    public function __invoke(): Response
    {
        $items = array_values(array_filter(
            $this->plans->findVisible(),
            static fn (BillingPlan $p) => $p->getCode() !== null,
        ));

        usort($items, static fn (BillingPlan $a, BillingPlan $b) => $a->getAmountCents() <=> $b->getAmountCents());

        return new JsonResponse([
            'items' => array_map(fn (BillingPlan $p) => [
                'code'           => $p->getCode(),
                'name'           => $p->getName(),
                'amount_cents'   => $p->getAmountCents(),
                'currency'       => $p->getCurrency(),
                'interval'       => $p->getInterval()->value,
                'interval_count' => $p->getIntervalCount(),
                'mode'           => $p->getMode()->value,
                'trial_days'     => $p->getTrialDays(),
            ], $items),
        ]);
    }
}
