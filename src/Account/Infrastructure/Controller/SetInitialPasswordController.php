<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Controller;

use App\Account\Domain\PasswordSetupTokenRepository;
use App\Auth\Infrastructure\Service\KeycloakService;
use App\Users\Domain\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Fija la contraseña inicial desde el correo de bienvenida.
 *
 * Público por necesidad: el usuario todavía no puede iniciar sesión, que es
 * precisamente lo que viene a resolver. Lo que le autoriza es el token del
 * correo, de un solo uso y con caducidad.
 *
 * El token se marca como usado **antes** de tocar Keycloak: si algo falla
 * después, el usuario pide otro correo. Al revés, un reintento con el mismo
 * enlace podría cambiar una contraseña ya establecida.
 */
#[Route('/public/account/initial-password', name: 'set_initial_password', methods: ['POST'])]
class SetInitialPasswordController
{
    private const MIN_LENGTH = 8;

    public function __construct(
        private readonly PasswordSetupTokenRepository $tokens,
        private readonly UserRepository $users,
        private readonly KeycloakService $keycloak,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): Response
    {
        $payload  = json_decode($request->getContent() ?: '{}', true);
        $plain    = is_array($payload) ? trim((string) ($payload['token'] ?? '')) : '';
        $password = is_array($payload) ? (string) ($payload['password'] ?? '') : '';

        if ($plain === '') {
            return new JsonResponse(['error' => 'token_required'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return new JsonResponse(
                ['error' => 'password_too_short', 'min_length' => self::MIN_LENGTH],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $token = $this->tokens->findByPlainToken($plain);

        // Mismo error para «no existe», «caducado» y «ya usado»: distinguirlos
        // le diría a quien pruebe enlaces cuáles han existido.
        if ($token === null || !$token->isUsable()) {
            return new JsonResponse(['error' => 'invalid_token'], Response::HTTP_GONE);
        }

        $user = $this->users->findById($token->getUserId());
        if ($user === null || $user->getEmail() === null) {
            return new JsonResponse(['error' => 'invalid_token'], Response::HTTP_GONE);
        }

        $keycloakId = $this->keycloak->findUserIdByEmail($user->getEmail());
        if ($keycloakId === null) {
            $this->logger->error('Token válido para un usuario que no está en Keycloak', [
                'user' => $user->getId(),
            ]);

            return new JsonResponse(['error' => 'account_not_found'], Response::HTTP_CONFLICT);
        }

        $token->markUsed();
        $this->tokens->save($token);

        try {
            $this->keycloak->setPassword($keycloakId, $password);
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo fijar la contraseña: {message}', [
                'message' => $e->getMessage(),
                'user'    => $user->getId(),
            ]);

            return new JsonResponse(['error' => 'could_not_set_password'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['ok' => true, 'email' => $user->getEmail()]);
    }
}
