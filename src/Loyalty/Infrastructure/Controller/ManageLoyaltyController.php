<?php

declare(strict_types=1);

namespace App\Loyalty\Infrastructure\Controller;

use App\Business\Application\ManagedBusinessFinder;
use App\Business\Domain\Business;
use App\Loyalty\Application\LoyaltyAvailability;
use App\Loyalty\Domain\LoyaltyCard;
use App\Loyalty\Domain\LoyaltyProgram;
use App\Loyalty\Domain\LoyaltyProgramRepository;
use App\Loyalty\Domain\LoyaltyToken;
use App\Loyalty\Domain\LoyaltyTokenKind;
use App\Loyalty\Domain\LoyaltyTokenRepository;
use App\Shared\Domain\UuidGenerator;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La tarjeta vista desde el negocio: sus premios y los QR que enseña.
 *
 * Los premios los puede editar también el panel (`business.edit`), como el
 * resto de la ficha. Los QR **no**: generarlos es dar sellos, y eso sólo lo
 * hace quien atiende el negocio.
 */
#[Route('/api/businesses/{id}/loyalty', name: 'manage_loyalty_')]
class ManageLoyaltyController
{
    public function __construct(
        private readonly ManagedBusinessFinder $managed,
        private readonly LoyaltyProgramRepository $programs,
        private readonly LoyaltyTokenRepository $tokens,
        private readonly LoyaltyAvailability $availability,
        private readonly LocalUserResolver $currentUser,
    ) {}

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(string $id): Response
    {
        $business = $this->authorize($id, allowBackoffice: true);
        if ($business instanceof Response) {
            return $business;
        }

        return new JsonResponse($this->serialize(
            $business,
            $this->programs->findByBusinessId($business->getId()),
        ));
    }

    /**
     * Body: {"rewards": {"3": {"label": "Café gratis", "description": "…"}, "5": null}}
     *
     * Un premio nulo o sin nombre lo quita; uno que no se manda se queda como
     * está. La descripción es opcional.
     * Se puede guardar aunque la tarifa no incluya la tarjeta: así el negocio la
     * tiene lista cuando cambie de tarifa o se la activen.
     */
    #[Route('', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request): Response
    {
        $business = $this->authorize($id, allowBackoffice: true);
        if ($business instanceof Response) {
            return $business;
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $rewards = is_array($payload) ? ($payload['rewards'] ?? null) : null;
        if (!is_array($rewards)) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        $errors = [];
        foreach ($rewards as $stage => $reward) {
            $label       = is_array($reward) ? ($reward['label'] ?? null) : null;
            $description = is_array($reward) ? ($reward['description'] ?? null) : null;

            if (!in_array((int) $stage, LoyaltyCard::REWARD_STAGES, true) || (string) (int) $stage !== (string) $stage) {
                $errors[(string) $stage] = 'invalid_stage';
            } elseif (($reward !== null && !is_array($reward))
                || ($label !== null && !is_string($label))
                || ($description !== null && !is_string($description))) {
                $errors[(string) $stage] = 'invalid';
            } elseif (is_string($label) && mb_strlen(trim($label)) > LoyaltyProgram::REWARD_MAX_LENGTH) {
                $errors[(string) $stage] = 'label_too_long';
            } elseif (is_string($description) && mb_strlen(trim($description)) > LoyaltyProgram::DESCRIPTION_MAX_LENGTH) {
                $errors[(string) $stage] = 'description_too_long';
            }
        }
        if ($errors !== []) {
            return new JsonResponse(
                ['error' => 'validation_failed', 'fields' => $errors],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $program = $this->programs->findByBusinessId($business->getId()) ?? new LoyaltyProgram($business->getId());
        foreach ($rewards as $stage => $reward) {
            $program->setReward((int) $stage, $reward['label'] ?? null, $reward['description'] ?? null);
        }
        $this->programs->save($program);

        return new JsonResponse($this->serialize($business, $program));
    }

    /**
     * Genera un QR. Body: {"kind": "stamp"} o {"kind": "redeem", "reward_stage": 3}
     *
     * El valor en claro sólo sale en esta respuesta.
     */
    #[Route('/tokens', name: 'issue_token', methods: ['POST'])]
    public function issueToken(string $id, Request $request): Response
    {
        $business = $this->authorize($id, allowBackoffice: false);
        if ($business instanceof Response) {
            return $business;
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $kind    = is_array($payload) ? LoyaltyTokenKind::tryFrom((string) ($payload['kind'] ?? '')) : null;
        $stage   = $kind === LoyaltyTokenKind::Redeem ? (int) ($payload['reward_stage'] ?? 0) : null;
        if ($kind === null) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        $program = $this->programs->findByBusinessId($business->getId());
        if (!$this->availability->check($business->getId(), $program)->isAvailable()) {
            return new JsonResponse(['error' => 'loyalty_unavailable'], Response::HTTP_CONFLICT);
        }
        if ($stage !== null && $program?->rewardFor($stage) === null) {
            return new JsonResponse(['error' => 'reward_unavailable'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $issued = LoyaltyToken::issue(
            UuidGenerator::generate(),
            $business->getId(),
            $kind,
            $stage,
            (string) $this->currentUser->currentId(),
        );
        $this->tokens->save($issued['token']);

        return new JsonResponse([
            'id'           => $issued['token']->getId(),
            'token'        => $issued['plain'],
            'kind'         => $kind->value,
            'reward_stage' => $stage,
            'reward_label' => $stage !== null ? $program?->rewardFor($stage) : null,
            'expires_at'   => $issued['token']->getExpiresAt()->format(\DATE_ATOM),
        ], Response::HTTP_CREATED);
    }

    /**
     * En qué ha quedado un QR. La pantalla del negocio lo consulta mientras lo
     * enseña, para saber cuándo lo ha escaneado el cliente.
     */
    #[Route('/tokens/{tokenId}', name: 'token_status', methods: ['GET'])]
    public function tokenStatus(string $id, string $tokenId): Response
    {
        $business = $this->authorize($id, allowBackoffice: false);
        if ($business instanceof Response) {
            return $business;
        }

        $token = $this->tokens->findById($tokenId);
        if ($token === null || $token->getBusinessId() !== $business->getId()) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'id'         => $token->getId(),
            'status'     => $token->isUsed() ? 'used' : ($token->isExpired() ? 'expired' : 'pending'),
            'used_at'    => $token->getUsedAt()?->format(\DATE_ATOM),
            'expires_at' => $token->getExpiresAt()->format(\DATE_ATOM),
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(Business $business, ?LoyaltyProgram $program): array
    {
        $rewards = [];
        foreach (LoyaltyCard::REWARD_STAGES as $stage) {
            $label = $program?->rewardFor($stage);
            $rewards[(string) $stage] = $label === null
                ? null
                : ['label' => $label, 'description' => $program->rewardDescription($stage)];
        }

        return $this->availability->check($business->getId(), $program)->toArray() + [
            'max_stamps' => LoyaltyCard::MAX_STAMPS,
            'rewards'    => $rewards,
        ];
    }

    /** @return Business|Response el negocio, o la respuesta de error */
    private function authorize(string $id, bool $allowBackoffice): Business|Response
    {
        if (!$this->managed->hasSession()) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // 404 tanto si no existe como si es de otro: ver ManagedBusinessFinder.
        return $this->managed->find($id, $allowBackoffice)
            ?? new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
    }
}
