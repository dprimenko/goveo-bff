<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

class KeycloakService
{
    private string $tokenUrl;
    private string $adminTokenUrl;
    private string $usersUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $keycloakUrl,
        private readonly string $keycloakRealm,
        private readonly string $keycloakClientId,
        private readonly string $keycloakClientSecret,
        private readonly string $keycloakAdminUser,
        private readonly string $keycloakAdminPassword,
    ) {
        $this->tokenUrl      = sprintf('%s/realms/%s/protocol/openid-connect/token', $keycloakUrl, $keycloakRealm);
        $this->adminTokenUrl = sprintf('%s/realms/master/protocol/openid-connect/token', $keycloakUrl);
        $this->usersUrl      = sprintf('%s/admin/realms/%s/users', $keycloakUrl, $keycloakRealm);
    }

    /**
     * Direct Access Grant — exchange username/password for tokens.
     */
    public function loginWithPassword(string $username, string $password): array
    {
        $response = $this->httpClient->request('POST', $this->tokenUrl, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'grant_type'    => 'password',
                'client_id'     => $this->keycloakClientId,
                'client_secret' => $this->keycloakClientSecret,
                'username'      => $username,
                'password'      => $password,
                'scope'         => 'openid profile email',
            ],
        ]);

        return $response->toArray();
    }

    /**
     * Refresh an access token using a refresh_token.
     */
    public function refreshToken(string $refreshToken): array
    {
        $response = $this->httpClient->request('POST', $this->tokenUrl, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'grant_type'    => 'refresh_token',
                'client_id'     => $this->keycloakClientId,
                'client_secret' => $this->keycloakClientSecret,
                'refresh_token' => $refreshToken,
            ],
        ]);

        return $response->toArray();
    }

    /**
     * Revoke session in Keycloak (logout).
     */
    public function revokeToken(string $refreshToken): void
    {
        $logoutUrl = sprintf('%s/realms/%s/protocol/openid-connect/logout', $this->keycloakUrl, $this->keycloakRealm);

        $this->httpClient->request('POST', $logoutUrl, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'client_id'     => $this->keycloakClientId,
                'client_secret' => $this->keycloakClientSecret,
                'refresh_token' => $refreshToken,
            ],
        ]);
    }

    /**
     * Register a new user via Keycloak Admin API.
     * Returns the new user's Keycloak ID extracted from the Location header.
     */
    public function registerUser(string $email, string $password, string $firstName, string $lastName): string
    {
        $adminToken = $this->getAdminToken();

        $response = $this->httpClient->request('POST', $this->usersUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $adminToken,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'username'      => $email,
                'email'         => $email,
                'firstName'     => $firstName,
                'lastName'      => $lastName,
                'enabled'       => true,
                'emailVerified' => false,
                'credentials'   => [[
                    'type'      => 'password',
                    'value'     => $password,
                    'temporary' => false,
                ]],
            ],
        ]);

        if ($response->getStatusCode() !== 201) {
            $body = $response->toArray(throw: false);
            throw new \RuntimeException($body['errorMessage'] ?? 'Failed to create user in Keycloak.');
        }

        // Keycloak returns the new user URL in the Location header
        $location = $response->getHeaders()['location'][0] ?? '';
        $userId = basename($location);

        if (!$userId) {
            throw new \RuntimeException('Could not extract user ID from Keycloak response.');
        }

        return $userId;
    }

    /**
     * Crea el usuario **sin contraseña**, para el alta de negocio desde la web.
     *
     * Ahí el dueño no elige clave: da su email, paga, y después recibe un correo
     * para ponerse la suya. Se marca `UPDATE_PASSWORD` como acción obligatoria,
     * así que aunque alguien intentara entrar antes, Keycloak le forzaría a
     * definirla.
     *
     * Si el email ya existe devuelve su id en vez de fallar: que alguien dé de
     * alta un segundo negocio con el mismo correo es normal, no un error.
     *
     * @return array{id: string, created: bool}
     */
    public function createUserPendingPassword(
        string $email,
        string $firstName = '',
        string $lastName = '',
    ): array {
        $adminToken = $this->getAdminToken();

        $existing = $this->findUserIdByEmailWithToken($email, $adminToken);
        if ($existing !== null) {
            return ['id' => $existing, 'created' => false];
        }

        $response = $this->httpClient->request('POST', $this->usersUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $adminToken,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'username'        => $email,
                'email'           => $email,
                'firstName'       => $firstName,
                'lastName'        => $lastName,
                'enabled'         => true,
                'emailVerified'   => false,
                'requiredActions' => ['UPDATE_PASSWORD'],
            ],
        ]);

        if ($response->getStatusCode() === 409) {
            // Carrera con otro alta simultánea: se resuelve releyendo.
            $id = $this->findUserIdByEmailWithToken($email, $adminToken);
            if ($id !== null) {
                return ['id' => $id, 'created' => false];
            }
        }

        if ($response->getStatusCode() !== 201) {
            $body = $response->toArray(throw: false);
            throw new \RuntimeException($body['errorMessage'] ?? 'No se pudo crear el usuario en Keycloak.');
        }

        $userId = basename($response->getHeaders()['location'][0] ?? '');
        if (!$userId) {
            throw new \RuntimeException('Keycloak no devolvió el id del usuario creado.');
        }

        return ['id' => $userId, 'created' => true];
    }

    /**
     * Fija la contraseña de un usuario y le quita la acción pendiente de
     * cambiarla. Se usa al terminar el correo de bienvenida.
     */
    public function setPassword(string $keycloakUserId, string $password): void
    {
        $adminToken = $this->getAdminToken();

        $response = $this->httpClient->request(
            'PUT',
            sprintf('%s/%s/reset-password', $this->usersUrl, $keycloakUserId),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $adminToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => ['type' => 'password', 'value' => $password, 'temporary' => false],
            ],
        );

        if ($response->getStatusCode() >= 300) {
            $body = $response->toArray(throw: false);
            throw new \RuntimeException($body['errorMessage'] ?? 'No se pudo fijar la contraseña.');
        }

        // Sin esto Keycloak seguiría exigiendo cambiarla en el primer login,
        // justo después de que el usuario acabe de elegirla.
        $this->httpClient->request('PUT', sprintf('%s/%s', $this->usersUrl, $keycloakUserId), [
            'headers' => [
                'Authorization' => 'Bearer ' . $adminToken,
                'Content-Type'  => 'application/json',
            ],
            'json' => ['requiredActions' => [], 'emailVerified' => true],
        ]);
    }

    /**
     * Si al usuario todavía le falta ponerse contraseña.
     *
     * Lo dice Keycloak, que es la fuente de verdad: mientras conserve la acción
     * `UPDATE_PASSWORD` pendiente, no ha llegado a definirla. Sirve para no
     * mandar un correo de «crea tu contraseña» a quien ya entra con la suya.
     */
    public function hasPendingPasswordSetup(string $email): bool
    {
        $adminToken = $this->getAdminToken();
        $userId     = $this->findUserIdByEmailWithToken($email, $adminToken);

        if ($userId === null) {
            return false;
        }

        $response = $this->httpClient->request('GET', sprintf('%s/%s', $this->usersUrl, $userId), [
            'headers' => ['Authorization' => 'Bearer ' . $adminToken],
        ]);

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $user = $response->toArray(throw: false);

        return in_array('UPDATE_PASSWORD', $user['requiredActions'] ?? [], true);
    }

    /** Id del usuario en Keycloak, o null si no existe. No crea nada. */
    public function findUserIdByEmail(string $email): ?string
    {
        return $this->findUserIdByEmailWithToken($email, $this->getAdminToken());
    }

    private function findUserIdByEmailWithToken(string $email, string $adminToken): ?string
    {
        $response = $this->httpClient->request('GET', $this->usersUrl, [
            'headers' => ['Authorization' => 'Bearer ' . $adminToken],
            'query'   => ['email' => $email, 'exact' => 'true'],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $users = $response->toArray(throw: false);

        return $users[0]['id'] ?? null;
    }

    /**
     * Deshabilita al usuario en Keycloak.
     *
     * Sin esto, "marcar para borrado" no serviría de nada: el usuario volvería
     * a entrar en el siguiente login y la cuenta seguiría viva de hecho.
     */
    public function disableUser(string $userId): void
    {
        $adminToken = $this->getAdminToken();

        $response = $this->httpClient->request('PUT', $this->usersUrl . '/' . $userId, [
            'headers' => [
                'Authorization' => 'Bearer ' . $adminToken,
                'Content-Type'  => 'application/json',
            ],
            'json' => ['enabled' => false],
        ]);

        if (!in_array($response->getStatusCode(), [200, 204], true)) {
            throw new \RuntimeException('Failed to disable user in Keycloak.');
        }
    }

    /**
     * Exchange a social identity token for Goveo tokens via Keycloak.
     * Keycloak must have the corresponding Identity Provider configured.
     */
    /**
     * Canjea el token de un proveedor social por tokens del realm.
     *
     * `$tokenType` varía por proveedor y no es un detalle menor: Google entrega
     * un token de acceso que Keycloak valida contra su endpoint de usuario,
     * mientras que Apple **sólo** entrega un `identity token` —un JWT— y no hay
     * token de acceso que enviar. Declarar el tipo equivocado se manifiesta como
     * `invalid_token`, el mismo error que da un token caducado o falso, así que
     * cuesta de diagnosticar.
     */
    public function loginWithSocialToken(
        string $provider,
        string $accessToken,
        string $tokenType = 'urn:ietf:params:oauth:token-type:access_token',
    ): array {
        $response = $this->httpClient->request('POST', $this->tokenUrl, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'grant_type'             => 'urn:ietf:params:oauth:grant-type:token-exchange',
                'client_id'              => $this->keycloakClientId,
                'client_secret'          => $this->keycloakClientSecret,
                'subject_token'          => $accessToken,
                'subject_issuer'         => $provider,
                'subject_token_type'     => $tokenType,
                'requested_token_type'   => 'urn:ietf:params:oauth:token-type:refresh_token',
            ],
        ]);

        return $response->toArray();
    }

    private function getAdminToken(): string
    {
        $response = $this->httpClient->request('POST', $this->adminTokenUrl, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'grant_type' => 'password',
                'client_id'  => 'admin-cli',
                'username'   => $this->keycloakAdminUser,
                'password'   => $this->keycloakAdminPassword,
            ],
        ]);

        return $response->toArray()['access_token'];
    }
}
