<?php

declare(strict_types=1);

namespace App\Loyalty\Infrastructure\Controller;

use App\Loyalty\Application\ScanLoyaltyToken;
use App\Loyalty\Application\ScanOutcome;
use App\Loyalty\Application\ScanResult;
use App\Loyalty\Domain\LoyaltyCard;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * El cliente escanea el QR del negocio.
 *
 * Body: {"token": "<valor del QR>"}
 *
 * La respuesta siempre trae `outcome` (ver `ScanOutcome`), que es lo que la app
 * mira para decidir qué mensaje enseñar. El código HTTP acompaña: 200 cuando el
 * escaneo es válido, aunque no sume (tarjeta llena, sellos insuficientes); 4xx
 * cuando el QR no sirve.
 */
#[Route('/api/loyalty/scan', name: 'loyalty_scan', methods: ['POST'])]
class ScanLoyaltyTokenController
{
    public function __construct(
        private readonly ScanLoyaltyToken $scanner,
        private readonly LocalUserResolver $currentUser,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(Request $request): Response
    {
        $userId = $this->currentUser->currentId();
        if ($userId === null) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $token   = is_array($payload) && is_string($payload['token'] ?? null) ? trim($payload['token']) : '';
        if ($token === '') {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        /** @var ScanResult $result */
        $result = $this->em->wrapInTransaction(fn () => $this->scanner->scan($token, $userId));

        return new JsonResponse([
            'outcome'      => $result->outcome->value,
            'business_id'  => $result->businessId,
            'stamps'       => $result->stamps,
            'max_stamps'   => LoyaltyCard::MAX_STAMPS,
            'reward_stage' => $result->rewardStage,
            'reward_label' => $result->rewardLabel,
        ], $this->status($result->outcome));
    }

    private function status(ScanOutcome $outcome): int
    {
        return match ($outcome) {
            ScanOutcome::Invalid                                        => Response::HTTP_NOT_FOUND,
            ScanOutcome::Expired, ScanOutcome::AlreadyUsed              => Response::HTTP_GONE,
            ScanOutcome::Unavailable, ScanOutcome::RewardUnavailable    => Response::HTTP_CONFLICT,
            default                                                     => Response::HTTP_OK,
        };
    }
}
