<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Controller;

use App\Account\Domain\TermsAcceptance;
use App\Account\Domain\TermsAcceptanceRepository;
use App\Shared\Domain\UuidGenerator;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * El usuario acepta las condiciones de uso en su versión `version`.
 *
 * Existe por Apple (guideline 1.2): las condiciones —con su tolerancia cero
 * con el contenido ofensivo— tienen que aceptarse **antes** de usar la app con
 * cuenta, y quien entraba con Google o Apple se saltaba la casilla del
 * registro. La versión la pone la app, que es quien enseña el texto; aquí sólo
 * queda constancia. `/api/auth/me` devuelve la última aceptada.
 *
 * Idempotente. Body: {"version": "2026-10"}
 */
#[Route('/api/account/terms', name: 'account_accept_terms', methods: ['POST'])]
class AcceptTermsController
{
    public function __construct(
        private readonly TermsAcceptanceRepository $acceptances,
        private readonly LocalUserResolver $currentUser,
    ) {}

    public function __invoke(Request $request): Response
    {
        $userId = $this->currentUser->currentId();

        if ($userId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $version = is_array($payload) ? ($payload['version'] ?? null) : null;

        if (!is_string($version) || !preg_match('/^[\w.\-]{1,32}$/', $version)) {
            return new JsonResponse(['error' => 'version is required'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->acceptances->has($userId, $version)) {
            $this->acceptances->save(new TermsAcceptance(UuidGenerator::generate(), $userId, $version));
        }

        return new JsonResponse(['terms_version' => $version]);
    }
}
