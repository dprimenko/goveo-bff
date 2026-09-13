<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Controller;

use App\Auth\Infrastructure\Service\KeycloakService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /public/account/password-reset  `{email}`
 *
 * «He olvidado mi contraseña», pedido desde la app. Manda el correo con el
 * enlace para elegir una nueva.
 *
 * **Siempre responde 204**, exista la cuenta o no, y sin decir qué ha pasado. Es
 * la única respuesta posible: distinguir «te lo he mandado» de «aquí no hay
 * nadie» convierte el endpoint en un comprobador de qué direcciones tienen
 * cuenta en Goveo, que es media lista de clientes regalada a quien pruebe
 * correos. Por lo mismo **falla en silencio**: que Keycloak esté caído tampoco
 * puede leerse desde fuera; queda en el log, que es donde se mira.
 *
 * **Público por necesidad**: quien no puede entrar es justo quien lo pide.
 *
 * El correo y el enlace los hace Keycloak (`UPDATE_PASSWORD`), con el tema de
 * Goveo — ver `keycloak-theme/`. Aquí no se toca ninguna contraseña: esto sólo
 * enciende la mecha.
 */
final class RequestPasswordResetController
{
    public function __construct(
        private readonly KeycloakService $keycloak,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/public/account/password-reset', name: 'public_password_reset', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = $request->toArray();
        $email   = trim((string) ($payload['email'] ?? ''));

        // La única comprobación que se hace aquí: sin arroba no hay nada que
        // buscar, y así un formulario vacío no llega a molestar a Keycloak.
        if ($email === '' || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        try {
            $userId = $this->keycloak->findUserIdByEmail($email);

            if ($userId === null) {
                // Se registra para poder distinguir después «no le llegó» de
                // «no existe», que es la pregunta que llega a soporte.
                $this->logger->info('Recuperación de contraseña para una dirección sin cuenta.');
            } else {
                $this->keycloak->sendPasswordResetEmail($userId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo mandar la recuperación de contraseña: {message}', [
                'message' => $e->getMessage(),
            ]);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
