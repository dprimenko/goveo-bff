<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Command;

use App\Auth\Infrastructure\Service\KeycloakService;
use App\Users\Domain\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Limpia las altas web que nunca llegaron a pagarse.
 *
 * El formulario público crea la cuenta y el negocio **antes** de cobrar, así que
 * quien abandona en la pasarela deja un negocio sin validar, una suscripción en
 * `pending_payment` y un usuario que jamás ha entrado.
 *
 * Se reconocen sin ambigüedad: suscripción `pending_payment`, sin
 * `stripe_subscription_id` y con cierta antigüedad. Nada de esto se cumple en un
 * alta que sí pagó.
 *
 * **No borra nada por defecto**: hay que pasar `--force`. Y sólo toca al usuario
 * si ese negocio era el único que gestionaba; quien ya tenga otro negocio se
 * queda como está.
 */
#[AsCommand(
    name: 'goveo:business:purge-abandoned',
    description: 'Elimina altas web sin pagar y sus usuarios huérfanos.',
)]
final class PurgeAbandonedRegistrationsCommand extends Command
{
    private const DEFAULT_DAYS = 7;

    public function __construct(
        private readonly Connection $connection,
        private readonly UserRepository $users,
        private readonly KeycloakService $keycloak,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Antigüedad mínima en días', (string) self::DEFAULT_DAYS);
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Borra de verdad; sin esto sólo informa');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $days  = max(0, (int) $input->getOption('days'));
        $force = (bool) $input->getOption('force');

        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT b.id AS business_id, b.name, b.slug, b.creator_id,
                   u.email, s.id AS subscription_id, s.created_at,
                   (SELECT COUNT(*) FROM business_managers m2
                     WHERE m2.user_id = b.creator_id) AS managed_count
            FROM business_subscriptions s
            JOIN business b ON b.id = s.business_id
            LEFT JOIN users u ON u.id = b.creator_id
            WHERE s.status = 'pending_payment'
              AND s.stripe_subscription_id IS NULL
              AND s.created_at < NOW() - (:days || ' days')::interval
            ORDER BY s.created_at
        SQL, ['days' => $days]);

        $io->title(sprintf('Altas sin pagar con más de %d día(s)', $days));

        if ($rows === []) {
            $io->success('No hay nada que limpiar.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Negocio', 'Email', 'Creada', 'Otros negocios'],
            array_map(fn (array $r) => [
                $r['slug'],
                $r['email'] ?? '(sin usuario)',
                substr((string) $r['created_at'], 0, 10),
                // Si gestiona más de éste, el usuario se conserva.
                ((int) $r['managed_count']) > 1 ? 'sí — se conserva' : 'no',
            ], $rows),
        );

        if (!$force) {
            $io->warning(sprintf('%d altas listas para borrar. Repite con --force.', count($rows)));

            return Command::SUCCESS;
        }

        $deletedBusinesses = $deletedUsers = $keycloakErrors = 0;

        foreach ($rows as $row) {
            $this->connection->beginTransaction();
            try {
                $this->connection->delete('business_subscriptions', ['id' => $row['subscription_id']]);
                $this->connection->delete('business_managers', ['business_id' => $row['business_id']]);
                $this->connection->delete('business', ['id' => $row['business_id']]);

                // El usuario sólo se va si este negocio era el único suyo.
                $isOnlyBusiness = ((int) $row['managed_count']) <= 1;
                if ($isOnlyBusiness && $row['creator_id'] !== null) {
                    $this->connection->delete('users', ['id' => $row['creator_id']]);
                    ++$deletedUsers;
                }

                $this->connection->commit();
                ++$deletedBusinesses;

                // Keycloak fuera de la transacción: no puede deshacerse, así que
                // se hace cuando la base ya está confirmada.
                if ($isOnlyBusiness && $row['email'] !== null) {
                    try {
                        // Buscar, nunca crear: usar el alta para obtener el id
                        // crearía la cuenta que se pretende retirar.
                        $keycloakId = $this->keycloak->findUserIdByEmail((string) $row['email']);
                        if ($keycloakId !== null) {
                            $this->keycloak->disableUser($keycloakId);
                        }
                    } catch (\Throwable $e) {
                        ++$keycloakErrors;
                        $io->writeln(sprintf(
                            '  <comment>aviso</comment> no se pudo deshabilitar %s en Keycloak: %s',
                            $row['email'],
                            $e->getMessage(),
                        ));
                    }
                }
            } catch (\Throwable $e) {
                $this->connection->rollBack();
                $io->writeln(sprintf('  <error>fallo</error> %s: %s', $row['slug'], $e->getMessage()));
            }
        }

        $io->success(sprintf(
            '%d altas eliminadas, %d usuarios locales borrados, %d avisos de Keycloak.',
            $deletedBusinesses,
            $deletedUsers,
            $keycloakErrors,
        ));

        return Command::SUCCESS;
    }
}
