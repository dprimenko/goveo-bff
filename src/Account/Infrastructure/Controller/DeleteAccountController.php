<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Controller;

use App\Auth\Infrastructure\Service\KeycloakService;
use App\Security\GoveoUser;
use App\Users\Domain\UserRepository;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Borrado de cuenta — requisito de App Store y Play Store.
 *
 * Marca `users.deleted_at` y **deshabilita al usuario en Keycloak**. Lo segundo
 * no es opcional: sin ello el usuario volvería a entrar en el siguiente login y
 * la cuenta seguiría viva de hecho, que es justo lo que las stores rechazan.
 *
 * El purgado real (contenido, follows, likes) lo hace un proceso posterior; se
 * conserva lo que no es suyo — los negocios son entidades comerciales que
 * sobreviven a quien los gestionaba.
 */
#[Route('/api/account', name: 'account_delete', methods: ['DELETE'])]
class DeleteAccountController
{
    public function __construct(
        private readonly Security $security,
        private readonly UserRepository $users,
        private readonly LocalUserResolver $currentUser,
        private readonly KeycloakService $keycloak,
    ) {}

    public function __invoke(): Response
    {
        $localId = $this->currentUser->currentId();

        if ($localId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $user = $this->users->findById($localId);

        if ($user !== null && !$user->isDeleted()) {
            $user->softDelete();
            $this->users->save($user);
        }

        // El id de Keycloak es el `sub` del JWT, no el id local.
        $principal = $this->security->getUser();

        if ($principal instanceof GoveoUser) {
            try {
                $this->keycloak->disableUser($principal->getId());
            } catch (\Throwable) {
                // La marca ya está puesta; que falle Keycloak no debe dejar al
                // usuario sin respuesta. Queda para el proceso de purgado.
                return new JsonResponse(
                    ['deleted' => true, 'signInDisabled' => false],
                    Response::HTTP_ACCEPTED,
                );
            }
        }

        return new JsonResponse(['deleted' => true, 'signInDisabled' => true]);
    }
}
