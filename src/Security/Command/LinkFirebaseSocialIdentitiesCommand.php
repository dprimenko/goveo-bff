<?php

declare(strict_types=1);

namespace App\Security\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Vincula en Keycloak las cuentas sociales que los usuarios ya tenían en Firebase.
 *
 * La migración de usuarios (`goveo:migrate:firebase-auth-to-keycloak`) trajo el
 * correo y el hash de la contraseña, pero **no** las identidades federadas. El
 * resultado es que quien entraba con Google en la app antigua se encuentra un
 * `federated_identity_account_exists` al intentarlo en ésta: Keycloak ve un
 * usuario con ese correo, no le encuentra vínculo con el proveedor, y aborta.
 *
 * No lo arregla el flujo «goveo auto link» del realm: ése sólo corre en el login
 * por navegador, y la app canjea el token del SDK nativo por `token-exchange`,
 * que no pasa por ningún flujo de autenticación.
 *
 * El `rawId` que Firebase guarda en `providerUserInfo` **es** el `sub` que emite
 * el proveedor, que es exactamente lo que Keycloak usa como identificador del
 * vínculo. Por eso el enlace que se crea aquí es el de verdad y no una
 * aproximación: el mismo que se habría creado si el usuario hubiera entrado por
 * el navegador.
 *
 * Facebook se queda fuera a propósito: el realm no tiene ese proveedor, así que
 * un vínculo no serviría de nada. Esos usuarios entran con contraseña.
 */
#[AsCommand(
    name: 'goveo:migrate:firebase-social-links',
    description: 'Creates the Keycloak federated identity links (google/apple) that Firebase users already had.',
)]
final class LinkFirebaseSocialIdentitiesCommand extends Command
{
    /** providerId de Firebase → alias del Identity Provider en Keycloak. */
    private const PROVIDER_ALIASES = [
        'google.com' => 'google',
        'apple.com'  => 'apple',
    ];

    protected function configure(): void
    {
        $this
            ->addOption('kc-url',      null, InputOption::VALUE_REQUIRED, 'Keycloak base URL',       'http://keycloak:8080')
            ->addOption('kc-realm',    null, InputOption::VALUE_REQUIRED, 'Keycloak realm',          'goveo')
            ->addOption('kc-admin',    null, InputOption::VALUE_REQUIRED, 'Keycloak admin username', 'admin')
            ->addOption('kc-password', null, InputOption::VALUE_REQUIRED, 'Keycloak admin password', 'admin123')
            ->addOption(
                'firebase-export-file', null, InputOption::VALUE_REQUIRED,
                'Path to a Firebase CLI JSON export (firebase auth:export users.json).',
            )
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Only this provider (google|apple)')
            ->addOption('dry-run',  null, InputOption::VALUE_NONE,     'Report what would be linked, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Firebase social identities → Keycloak federated links');

        $dryRun     = (bool)   $input->getOption('dry-run');
        $kcUrl      = rtrim((string) $input->getOption('kc-url'), '/');
        $kcRealm    = (string) $input->getOption('kc-realm');
        $kcAdmin    = (string) $input->getOption('kc-admin');
        $kcPassword = (string) $input->getOption('kc-password');
        $exportFile = (string) $input->getOption('firebase-export-file');
        $only       = (string) ($input->getOption('provider') ?? '');

        if ($dryRun) {
            $io->warning('DRY RUN — no changes will be written to Keycloak.');
        }

        if ($exportFile === '' || !is_readable($exportFile)) {
            $io->error([
                'Missing or unreadable --firebase-export-file.',
                '',
                '  firebase auth:export users.json --format=json',
                '  docker cp users.json goveo-bff-php-1:/tmp/users.json',
                '  php bin/console goveo:migrate:firebase-social-links --firebase-export-file=/tmp/users.json',
            ]);

            return Command::FAILURE;
        }

        if ($only !== '' && !in_array($only, self::PROVIDER_ALIASES, true)) {
            $io->error(sprintf('Unknown --provider "%s". Use one of: %s', $only, implode(', ', self::PROVIDER_ALIASES)));

            return Command::FAILURE;
        }

        $json  = json_decode((string) file_get_contents($exportFile), true, flags: JSON_THROW_ON_ERROR);
        $users = $json['users'] ?? [];
        $io->info(sprintf('Loaded %d users from %s', count($users), $exportFile));

        $http    = HttpClient::create();
        $kcToken = $this->getAdminToken($http, $kcUrl, $kcAdmin, $kcPassword);
        $io->success('Keycloak admin token obtained.');

        $stats = ['linked' => 0, 'already' => 0, 'no_user' => 0, 'conflict' => 0, 'unsupported' => 0, 'errors' => 0];

        foreach ($users as $user) {
            $email = (string) ($user['email'] ?? '');
            if ($email === '') {
                continue;
            }

            // Se resuelve el usuario de Keycloak una sola vez por cuenta, no por
            // proveedor: hay quien tiene Google y Apple a la vez.
            $keycloakId = null;

            foreach ($user['providerUserInfo'] ?? [] as $identity) {
                $alias = self::PROVIDER_ALIASES[$identity['providerId'] ?? ''] ?? null;
                if ($alias === null) {
                    ++$stats['unsupported'];
                    continue;
                }
                if ($only !== '' && $alias !== $only) {
                    continue;
                }

                $subject = (string) ($identity['rawId'] ?? '');
                if ($subject === '') {
                    ++$stats['errors'];
                    $io->writeln(sprintf('  <error>%-45s %s — sin rawId</error>', $email, $alias));
                    continue;
                }

                try {
                    $keycloakId ??= $this->findUserIdByEmail($http, $kcUrl, $kcRealm, $kcToken, $email);
                    if ($keycloakId === null) {
                        ++$stats['no_user'];
                        $io->writeln(sprintf('  <comment>%-45s %s — no está en Keycloak</comment>', $email, $alias));
                        break;
                    }

                    $existing = $this->findLinkedSubject($http, $kcUrl, $kcRealm, $kcToken, $keycloakId, $alias);

                    if ($existing === $subject) {
                        ++$stats['already'];
                        continue;
                    }

                    // Un vínculo con **otro** sub no se pisa: o el usuario ya
                    // entró y Keycloak le creó el suyo, o hay dos cuentas
                    // distintas compartiendo correo. Pisarlo dejaría fuera a
                    // quien está entrando hoy, que es peor que no tocar nada.
                    if ($existing !== null) {
                        ++$stats['conflict'];
                        $io->writeln(sprintf('  <comment>%-45s %s — ya vinculado a otro sub (%s)</comment>', $email, $alias, $existing));
                        continue;
                    }

                    if (!$dryRun) {
                        $this->linkIdentity($http, $kcUrl, $kcRealm, $kcToken, $keycloakId, $alias, $subject, $email);
                    }

                    ++$stats['linked'];
                    $io->writeln(sprintf('  <info>%-45s %s — %s</info>', $email, $alias, $dryRun ? 'DRY-RUN' : 'LINKED'));
                } catch (\Throwable $e) {
                    ++$stats['errors'];
                    $io->writeln(sprintf('  <error>%-45s %s — %s</error>', $email, $alias, $e->getMessage()));
                }
            }
        }

        $io->section('Summary');
        $io->table(
            ['Result', 'Count'],
            [
                ['Linked',                (string) $stats['linked']],
                ['Already linked',        (string) $stats['already']],
                ['User not in Keycloak',  (string) $stats['no_user']],
                ['Linked to another sub', (string) $stats['conflict']],
                ['Unsupported provider',  (string) $stats['unsupported']],
                ['Errors',                (string) $stats['errors']],
            ],
        );

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function getAdminToken(HttpClientInterface $http, string $kcUrl, string $admin, string $password): string
    {
        $data = $http->request('POST', "{$kcUrl}/realms/master/protocol/openid-connect/token", [
            'body' => [
                'grant_type' => 'password',
                'client_id'  => 'admin-cli',
                'username'   => $admin,
                'password'   => $password,
            ],
        ])->toArray();

        return $data['access_token'] ?? throw new \RuntimeException('No access_token in Keycloak response');
    }

    private function findUserIdByEmail(
        HttpClientInterface $http,
        string $kcUrl,
        string $realm,
        string $token,
        string $email,
    ): ?string {
        $users = $http->request('GET', "{$kcUrl}/admin/realms/{$realm}/users", [
            'auth_bearer' => $token,
            'query'       => ['email' => $email, 'exact' => 'true', 'max' => 1],
        ])->toArray();

        return isset($users[0]['id']) ? (string) $users[0]['id'] : null;
    }

    /** El `sub` con el que ya está vinculado ese proveedor, o null si no lo está. */
    private function findLinkedSubject(
        HttpClientInterface $http,
        string $kcUrl,
        string $realm,
        string $token,
        string $userId,
        string $alias,
    ): ?string {
        $links = $http->request('GET', "{$kcUrl}/admin/realms/{$realm}/users/{$userId}/federated-identity", [
            'auth_bearer' => $token,
        ])->toArray();

        foreach ($links as $link) {
            if (($link['identityProvider'] ?? null) === $alias) {
                return (string) ($link['userId'] ?? '');
            }
        }

        return null;
    }

    private function linkIdentity(
        HttpClientInterface $http,
        string $kcUrl,
        string $realm,
        string $token,
        string $userId,
        string $alias,
        string $subject,
        string $username,
    ): void {
        $response = $http->request('POST', "{$kcUrl}/admin/realms/{$realm}/users/{$userId}/federated-identity/{$alias}", [
            'auth_bearer' => $token,
            'json'        => [
                'identityProvider' => $alias,
                'userId'           => $subject,
                'userName'         => $username,
            ],
        ]);

        // 409 = ya existía. El comando se puede repetir sin miedo, que es lo que
        // se acaba haciendo cuando una tanda se corta a medias.
        if (!in_array($response->getStatusCode(), [201, 204, 409], true)) {
            throw new \RuntimeException('HTTP ' . $response->getStatusCode() . ' — ' . $response->getContent(false));
        }
    }
}
