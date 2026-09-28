<?php

declare(strict_types=1);

namespace App\Account\Application;

use App\Auth\Infrastructure\Service\KeycloakService;
use App\Users\Domain\User;
use App\Users\Domain\UserRepository;
use Symfony\Component\Uid\Uuid;

/**
 * La cuenta de un correo: la que ya tiene, o una nueva **sin contraseña**.
 *
 * La nueva se crea en Keycloak pendiente de elegir contraseña
 * (`UPDATE_PASSWORD`) y en nuestra base; el correo de bienvenida
 * (`WelcomeMailer`) le manda el enlace para ponerla. Si el correo ya tiene
 * cuenta, se usa esa y la bienvenida no le pide nada.
 *
 * Los dos ids no coinciden —el local no es el de Keycloak—: se unen por el
 * correo. Lo usan el alta pública y el cambio de tarifa desde el panel.
 */
final class AccountProvisioner
{
    public function __construct(
        private readonly KeycloakService $keycloak,
        private readonly UserRepository $users,
    ) {}

    /** @return array{user: User, created: bool} `created`: la cuenta de Keycloak es nueva */
    public function forEmail(string $email, string $firstName = '', string $lastName = ''): array
    {
        $email   = strtolower(trim($email));
        $account = $this->keycloak->createUserPendingPassword(
            email:     $email,
            firstName: $firstName,
            lastName:  $lastName,
        );

        $user = $this->users->findByEmail($email);
        if ($user === null) {
            $user = new User(
                id:    Uuid::v4()->toRfc4122(),
                email: $email,
                name:  trim(sprintf('%s %s', $firstName, $lastName)) ?: null,
            );
            $this->users->save($user);
        }

        return ['user' => $user, 'created' => $account['created']];
    }
}
