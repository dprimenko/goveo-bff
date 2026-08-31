<?php

declare(strict_types=1);

namespace App\Security\Command;

use Kreait\Firebase\Factory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'goveo:migrate:firebase-auth-to-keycloak',
    description: 'Migrates Firebase Authentication users (including password hashes) to Keycloak.',
)]
final class MigrateFirebaseAuthToKeycloakCommand extends Command
{
    // Scrypt parameter keys as expected by the keycloak-firebase-scrypt SPI
    private const CRED_DATA_MEM_COST       = 'mem_cost';
    private const CRED_DATA_ROUNDS         = 'rounds';
    private const CRED_DATA_SALT_SEPARATOR = 'base64_salt_separator';
    private const CRED_DATA_SIGNER_KEY     = 'base64_signer_key';

    // Firebase returns this base64 string when the service account cannot read the real hash
    private const REDACTED_HASH = 'UkVEQUNURUQ=';

    protected function configure(): void
    {
        $this
            ->addOption('kc-url',      null, InputOption::VALUE_REQUIRED, 'Keycloak base URL',       'http://keycloak:8080')
            ->addOption('kc-realm',    null, InputOption::VALUE_REQUIRED, 'Keycloak realm',          'goveo')
            ->addOption('kc-admin',    null, InputOption::VALUE_REQUIRED, 'Keycloak admin username', 'admin')
            ->addOption('kc-password', null, InputOption::VALUE_REQUIRED, 'Keycloak admin password', 'admin123')
            ->addOption(
                'signer-key', null, InputOption::VALUE_REQUIRED,
                'Firebase scrypt Base64 Signer Key — Firebase Console → Authentication → Users → ⋮ → "Password hash parameters"',
            )
            ->addOption('salt-separator', null, InputOption::VALUE_REQUIRED, 'Firebase scrypt Base64 Salt Separator', 'Bw==')
            ->addOption('rounds',         null, InputOption::VALUE_REQUIRED, 'Firebase scrypt Rounds',      '8')
            ->addOption('mem-cost',       null, InputOption::VALUE_REQUIRED, 'Firebase scrypt Memory Cost', '14')
            ->addOption(
                'firebase-export-file', null, InputOption::VALUE_REQUIRED,
                'Path to a Firebase CLI JSON export (firebase auth:export users.json). Required to migrate password hashes.',
            )
            ->addOption('dry-run',       null, InputOption::VALUE_NONE, 'Simulate import without writing to Keycloak')
            ->addOption('skip-existing', null, InputOption::VALUE_NONE, 'Skip users that already exist in Keycloak (default: update)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Firebase Auth → Keycloak Migration');

        $dryRun       = (bool)   $input->getOption('dry-run');
        $skipExisting = (bool)   $input->getOption('skip-existing');
        $kcUrl        = rtrim((string) $input->getOption('kc-url'), '/');
        $kcRealm      = (string) $input->getOption('kc-realm');
        $kcAdmin      = (string) $input->getOption('kc-admin');
        $kcPassword   = (string) $input->getOption('kc-password');
        $exportFile   = (string) $input->getOption('firebase-export-file');
        $signerKey    = (string) $input->getOption('signer-key');
        $saltSep      = (string) $input->getOption('salt-separator');
        $rounds       = (int)    $input->getOption('rounds');
        $memCost      = (int)    $input->getOption('mem-cost');

        if ($dryRun) {
            $io->warning('DRY RUN — no changes will be written to Keycloak.');
        }

        // --- 1. Validate scrypt params ---
        if ($signerKey === '') {
            $io->error([
                'Missing required option: --signer-key',
                '',
                'How to get it:',
                '  1. Firebase Console → Authentication → Users',
                '  2. ⋮ menu (top right) → "Password hash parameters"',
                '  3. Copy the base64_signer_key value',
                '',
                'Then run:',
                '  php bin/console goveo:migrate:firebase-auth-to-keycloak \\',
                '    --signer-key="<paste here>" [--firebase-export-file=users.json] [--dry-run]',
            ]);

            return Command::FAILURE;
        }

        // --- 2. Load users ---
        $io->section('Loading Firebase users...');

        if ($exportFile !== '') {
            if (!is_readable($exportFile)) {
                $io->error("Cannot read export file: {$exportFile}");
                return Command::FAILURE;
            }

            $users = $this->loadUsersFromExportFile($exportFile);
            $io->info(sprintf('Loaded %d users from export file: %s', count($users), $exportFile));
        } else {
            $io->note([
                'No --firebase-export-file provided — loading users via Firebase Admin API.',
                'WARNING: password hashes are REDACTED by Firebase when using a service account.',
                'Users with passwords will be imported WITHOUT credentials (social login only).',
                '',
                'To include password hashes, export first:',
                '  firebase auth:export users.json --format=json',
                '  docker cp users.json goveo-bff-php-1:/tmp/users.json',
                '  # then re-run with: --firebase-export-file=/tmp/users.json',
            ]);

            $users = $this->loadUsersViaApi();
            $io->info(sprintf('Loaded %d users via Firebase Admin API.', count($users)));
        }

        // --- 3. Authenticate to Keycloak ---
        $io->section('Authenticating to Keycloak Admin API...');
        $http    = HttpClient::create();
        $kcToken = $this->getKeycloakAdminToken($http, $kcUrl, $kcAdmin, $kcPassword);
        $io->success('Keycloak admin token obtained.');

        // --- 4. Import ---
        $io->section('Importing users into Keycloak...');
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'with_password' => 0, 'no_password' => 0, 'errors' => 0];

        foreach ($users as $user) {
            if ($user->email === null) {
                ++$stats['skipped'];
                continue;
            }

            $io->write(sprintf('  %-45s → ', $user->email));

            try {
                $existingId = $this->findKeycloakUserByEmail($http, $kcUrl, $kcRealm, $kcToken, $user->email);

                if ($existingId !== null && $skipExisting) {
                    $io->writeln('<comment>SKIP (exists)</comment>');
                    ++$stats['skipped'];
                    continue;
                }

                [$firstName, $lastName] = $this->splitDisplayName($user->displayName ?? '');
                $userData = [
                    'username'      => $user->email,
                    'email'         => $user->email,
                    'emailVerified' => $user->emailVerified,
                    'enabled'       => !$user->disabled,
                    'firstName'     => $firstName,
                    'lastName'      => $lastName,
                ];

                $hasPassword = $user->passwordHash !== null && $user->passwordSalt !== null;
                if ($hasPassword) {
                    $userData['credentials'] = [
                        $this->buildFirebaseScryptCredential(
                            $user->passwordHash,
                            $user->passwordSalt,
                            $signerKey,
                            $saltSep,
                            $rounds,
                            $memCost,
                        ),
                    ];
                }

                if (!$dryRun) {
                    if ($existingId === null) {
                        $this->createKeycloakUser($http, $kcUrl, $kcRealm, $kcToken, $userData);
                        ++$stats['created'];
                    } else {
                        $this->updateKeycloakUser($http, $kcUrl, $kcRealm, $kcToken, $existingId, $userData);
                        ++$stats['updated'];
                    }
                }

                $label  = $hasPassword ? '<info>with password</info>' : 'social only';
                $action = $dryRun ? 'DRY-RUN' : ($existingId === null ? 'CREATED' : 'UPDATED');
                $io->writeln(sprintf('<info>%s</info> [%s]', $action, $label));

                $hasPassword ? ++$stats['with_password'] : ++$stats['no_password'];

            } catch (\Throwable $e) {
                $io->writeln('<error>ERROR: ' . $e->getMessage() . '</error>');
                ++$stats['errors'];
            }
        }

        // --- 5. Summary ---
        $io->section('Migration Summary');
        $io->table(
            ['Result', 'Count'],
            [
                ['Created',       (string) $stats['created']],
                ['Updated',       (string) $stats['updated']],
                ['Skipped',       (string) $stats['skipped']],
                ['With password', (string) $stats['with_password']],
                ['Social only',   (string) $stats['no_password']],
                ['Errors',        (string) $stats['errors']],
            ]
        );

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Firebase user loading
    // -------------------------------------------------------------------------

    /**
     * Parses a Firebase CLI JSON export (firebase auth:export users.json).
     * This is the only way to get real password hashes — the Admin API returns REDACTED.
     *
     * Export JSON fields: localId, email, emailVerified, displayName,
     *   passwordHash, salt, providerUserInfo[], disabled
     *
     * @return list<FirebaseUserDto>
     */
    private function loadUsersFromExportFile(string $path): array
    {
        $json = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $raw  = $json['users'] ?? [];

        return array_map(function (array $u): FirebaseUserDto {
            $hash = isset($u['passwordHash']) && $u['passwordHash'] !== '' ? $u['passwordHash'] : null;
            $salt = isset($u['salt'])         && $u['salt'] !== ''         ? $u['salt']         : null;

            // A hash without a salt is unusable for scrypt verification
            if ($hash !== null && $salt === null) {
                $hash = null;
            }

            return new FirebaseUserDto(
                email:         $u['email']       ?? null,
                emailVerified: (bool) ($u['emailVerified'] ?? false),
                displayName:   $u['displayName'] ?? null,
                disabled:      (bool) ($u['disabled'] ?? false),
                passwordHash:  $hash,
                passwordSalt:  $salt,
            );
        }, $raw);
    }

    /**
     * Loads users via kreait Firebase Admin API.
     * Password hashes will be REDACTED — users with passwords import without credentials.
     *
     * @return list<FirebaseUserDto>
     */
    private function loadUsersViaApi(): array
    {
        $serviceAccount = $this->loadServiceAccount();
        $factory = (new Factory())
            ->withServiceAccount(__DIR__ . '/../../../config/firebase-credentials.json')
            ->withProjectId($serviceAccount['project_id']);

        $users = [];
        foreach ($factory->createAuth()->listUsers() as $record) {
            $hash = $record->passwordHash;
            $salt = $record->passwordSalt;

            // Firebase returns base64("REDACTED") when the service account cannot read hashes
            if ($hash === self::REDACTED_HASH || $hash === 'REDACTED') {
                $hash = null;
                $salt = null;
            }

            $users[] = new FirebaseUserDto(
                email:         $record->email,
                emailVerified: $record->emailVerified ?? false,
                displayName:   $record->displayName,
                disabled:      $record->disabled ?? false,
                passwordHash:  $hash,
                passwordSalt:  $salt,
            );
        }

        return $users;
    }

    /** @return array<string,mixed> */
    private function loadServiceAccount(): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/../../../config/firebase-credentials.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    // -------------------------------------------------------------------------
    // Keycloak Admin API
    // -------------------------------------------------------------------------

    private function getKeycloakAdminToken(HttpClientInterface $http, string $kcUrl, string $admin, string $password): string
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

    private function findKeycloakUserByEmail(
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

    /** @param array<string,mixed> $userData */
    private function createKeycloakUser(
        HttpClientInterface $http,
        string $kcUrl,
        string $realm,
        string $token,
        array $userData,
    ): void {
        $response = $http->request('POST', "{$kcUrl}/admin/realms/{$realm}/users", [
            'auth_bearer' => $token,
            'json'        => $userData,
        ]);
        if ($response->getStatusCode() !== 201) {
            throw new \RuntimeException('Create user failed: HTTP ' . $response->getStatusCode() . ' — ' . $response->getContent(false));
        }
    }

    /** @param array<string,mixed> $userData */
    private function updateKeycloakUser(
        HttpClientInterface $http,
        string $kcUrl,
        string $realm,
        string $token,
        string $userId,
        array $userData,
    ): void {
        $response = $http->request('PUT', "{$kcUrl}/admin/realms/{$realm}/users/{$userId}", [
            'auth_bearer' => $token,
            'json'        => $userData,
        ]);
        if ($response->getStatusCode() !== 204) {
            throw new \RuntimeException('Update user failed: HTTP ' . $response->getStatusCode() . ' — ' . $response->getContent(false));
        }
    }

    // -------------------------------------------------------------------------
    // Credential builder
    // -------------------------------------------------------------------------

    /**
     * Builds a Keycloak credential for the firebase-scrypt password hash provider.
     *
     * All scrypt parameters go into credentialData.additionalParameters
     * (MultivaluedHashMap<String,String>) so each credential is self-contained.
     *
     * @return array<string,mixed>
     */
    private function buildFirebaseScryptCredential(
        string $passwordHash,
        string $passwordSalt,
        string $signerKey,
        string $saltSeparator,
        int $rounds,
        int $memCost,
    ): array {
        return [
            'type'           => 'password',
            'temporary'      => false,
            'credentialData' => json_encode([
                'hashIterations'       => -1,
                'algorithm'            => 'firebase-scrypt',
                'additionalParameters' => [
                    self::CRED_DATA_MEM_COST       => [(string) $memCost],
                    self::CRED_DATA_ROUNDS         => [(string) $rounds],
                    self::CRED_DATA_SALT_SEPARATOR => [$saltSeparator],
                    self::CRED_DATA_SIGNER_KEY     => [$signerKey],
                ],
            ], JSON_THROW_ON_ERROR),
            // value = AES-encrypted scrypt hash (base64 as exported by Firebase)
            // salt  = raw user salt bytes (base64, matches Jackson byte[] serialization in Keycloak)
            'secretData'     => json_encode([
                'value' => $passwordHash,
                'salt'  => $passwordSalt,
            ], JSON_THROW_ON_ERROR),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array{string, string} */
    private function splitDisplayName(string $displayName): array
    {
        $parts = explode(' ', trim($displayName), 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}

/**
 * Normalized Firebase user — populated from either the CLI export JSON or the Admin API.
 */
final class FirebaseUserDto
{
    public function __construct(
        public readonly ?string $email,
        public readonly bool    $emailVerified,
        public readonly ?string $displayName,
        public readonly bool    $disabled,
        /** Real scrypt hash (base64). Null for social-only users or when REDACTED by service account. */
        public readonly ?string $passwordHash,
        /** Raw salt bytes (base64). Null when passwordHash is null. */
        public readonly ?string $passwordSalt,
    ) {}
}
