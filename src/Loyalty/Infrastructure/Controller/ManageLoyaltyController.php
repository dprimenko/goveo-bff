<?php

declare(strict_types=1);

namespace App\Loyalty\Infrastructure\Controller;

use App\Backoffice\Application\ReviewQueueNotifier;
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
        private readonly ReviewQueueNotifier $notifier,
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
     * Body: {"rewards": {"3": {"label": "Café gratis", "description": "…"}, "5": null}, "active": true}
     *
     * Las dos partes son opcionales (al menos una):
     *
     * - `rewards`: un premio nulo o sin nombre lo quita; uno que no se manda se
     *   queda como está. La descripción es opcional. Se pueden guardar aunque la
     *   tarifa no incluya la tarjeta: así la tiene lista cuando cambie de tarifa
     *   o se la activen.
     * - `active`: enciende o apaga la tarjeta. Encenderla pide derecho (tarifa o
     *   activación manual) y al menos un premio —422 `cannot_activate` con el
     *   motivo—; apagarla siempre se puede y guarda los sellos de los clientes.
     *
     * Cuando lo cambia el propio negocio (no el panel), se avisa al equipo.
     */
    #[Route('', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request): Response
    {
        $business = $this->authorize($id, allowBackoffice: true);
        if ($business instanceof Response) {
            return $business;
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $rewards = is_array($payload) ? ($payload['rewards'] ?? []) : null;
        $active  = is_array($payload) ? ($payload['active'] ?? null) : null;
        if (!is_array($rewards) || ($active !== null && !is_bool($active))
            || ($rewards === [] && $active === null)) {
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
        $before  = ['rewards' => $program->rewards(), 'active' => $program->isActive()];

        foreach ($rewards as $stage => $reward) {
            $program->setReward((int) $stage, $reward['label'] ?? null, $reward['description'] ?? null);
        }

        if ($active === true) {
            $status = $this->availability->check($business->getId(), $program);
            if (!$status->canActivate()) {
                return new JsonResponse([
                    'error'  => 'cannot_activate',
                    'reason' => $status->isEnabled() ? 'no_rewards' : 'not_enabled',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        if ($active !== null) {
            $program->setActive($active);
        }
        $this->programs->save($program);

        if ($this->managed->isManager($business)) {
            $this->notifier->loyaltyChanged(
                $business,
                $this->availability->check($business->getId(), $program),
                self::changes($before, $program),
            );
        }

        return new JsonResponse($this->serialize($business, $program));
    }

    /**
     * Lo que ha cambiado, en frases para el correo al equipo.
     *
     * @param array{rewards: array<int, array{label: string, description: ?string}>, active: bool} $before
     *
     * @return string[]
     */
    private static function changes(array $before, LoyaltyProgram $program): array
    {
        $changes = [];
        $after   = $program->rewards();

        foreach (LoyaltyCard::REWARD_STAGES as $stage) {
            $old = $before['rewards'][$stage] ?? null;
            $new = $after[$stage] ?? null;
            if ($old == $new) {
                continue;
            }
            $changes[] = match (true) {
                $old === null => sprintf('Premio del %d: añade «%s»', $stage, $new['label']),
                $new === null => sprintf('Premio del %d: quita «%s»', $stage, $old['label']),
                default       => $old['label'] === $new['label']
                    ? sprintf('Premio del %d: cambia la descripción de «%s»', $stage, $new['label'])
                    : sprintf('Premio del %d: «%s» → «%s»', $stage, $old['label'], $new['label']),
            };
        }

        if ($before['active'] !== $program->isActive()) {
            $changes[] = $program->isActive() ? 'La ha encendido.' : 'La ha apagado.';
        }

        return $changes;
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
            'activated_at' => $program?->getActivatedAt()?->format(\DATE_ATOM),
            'max_stamps'   => LoyaltyCard::MAX_STAMPS,
            'rewards'      => $rewards,
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
