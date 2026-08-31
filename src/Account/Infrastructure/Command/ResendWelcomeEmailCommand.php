<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Command;

use App\Account\Application\WelcomeMailer;
use App\Business\Domain\BusinessRepository;
use App\Users\Domain\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reenvía el correo de bienvenida.
 *
 * Hace falta porque el envío **falla en silencio a propósito**: no puede tumbar
 * el webhook de Stripe ni deshacer un cobro. Sin este comando, un correo perdido
 * no se descubre hasta que el cliente escribe preguntando cómo entra.
 *
 *   goveo:account:resend-welcome              # lista a quién no le ha llegado
 *   goveo:account:resend-welcome bar-manolo   # reenvía a ese negocio
 *   goveo:account:resend-welcome --all        # a todos los pendientes
 *
 * Cada reenvío emite un token nuevo e **invalida los anteriores**: si se reenvía
 * es porque el correo se perdió o fue a quien no debía, y en el segundo caso
 * dejar vivo el enlace viejo sería regalar la cuenta.
 */
#[AsCommand(
    name: 'goveo:account:resend-welcome',
    description: 'Reenvía el correo de bienvenida, o lista a quién no le ha llegado.',
)]
final class ResendWelcomeEmailCommand extends Command
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly UserRepository $users,
        private readonly WelcomeMailer $welcome,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('business', InputArgument::OPTIONAL, 'Slug o id. Sin él, lista los pendientes.');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Reenvía a todos los pendientes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $ref = $input->getArgument('business');

        if ($ref !== null) {
            return $this->send($ref, $io) ? Command::SUCCESS : Command::FAILURE;
        }

        $pending = $this->pending();

        if ($pending === []) {
            $io->success('Todos los negocios con alta completada tienen su contraseña creada.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('%d negocio(s) sin acceso resuelto', count($pending)));
        $io->table(
            ['Slug', 'Negocio', 'Dueño', 'Motivo', 'Alta'],
            array_map(static fn (array $r) => [
                $r['slug'],
                $r['name'],
                $r['email'] ?? '(sin dueño)',
                $r['reason'],
                substr((string) $r['created_at'], 0, 10),
            ], $pending),
        );

        if (!$input->getOption('all')) {
            $io->writeln('  Reenviar uno:    <info>goveo:account:resend-welcome SLUG</info>');
            $io->writeln('  Reenviar todos:  <info>goveo:account:resend-welcome --all</info>');

            return Command::SUCCESS;
        }

        $sent = 0;
        foreach ($pending as $row) {
            if ($this->send((string) $row['slug'], $io)) {
                ++$sent;
            }
        }
        $io->success(sprintf('%d correos reenviados.', $sent));

        return Command::SUCCESS;
    }

    private function send(string $ref, SymfonyStyle $io): bool
    {
        $business = $this->businesses->findBySlug($ref) ?? $this->businesses->findById($ref);
        if ($business === null) {
            $io->error(sprintf('No hay ningún negocio con slug o id «%s».', $ref));

            return false;
        }

        $owner = $this->users->findById($business->getCreatorId());
        if ($owner === null || $owner->getEmail() === null) {
            $io->error(sprintf('«%s» no tiene dueño con email.', $business->getName()));

            return false;
        }

        // Los enlaces anteriores dejan de valer: reenviar significa que el
        // anterior no sirvió, y puede haber acabado donde no debía.
        //
        // Se **borran** en vez de marcarlos usados: `used_at` significa «el
        // usuario creó su contraseña», y ensuciarlo aquí haría imposible
        // distinguir a quien completó el proceso de quien nunca lo hizo.
        $invalidated = $this->connection->executeStatement(
            'DELETE FROM password_setup_tokens WHERE user_id = ? AND used_at IS NULL',
            [$owner->getId()],
        );

        $this->welcome->send($business, $owner->getId(), $owner->getEmail(), $owner->getName());

        // `send()` no lanza nunca; la marca es lo único que dice si salió.
        $refreshed = $this->businesses->findById($business->getId());
        $ok        = $refreshed?->getWelcomeEmailSentAt() !== null;

        $io->writeln(sprintf(
            '  %s %-28s → %s%s',
            $ok ? '<info>ENVIADO</info>' : '<error>FALLÓ  </error>',
            $business->getSlug(),
            $owner->getEmail(),
            $invalidated > 0 ? sprintf(' (%d enlace(s) anterior(es) invalidado(s))', $invalidated) : '',
        ));

        return $ok;
    }

    /**
     * Dos situaciones distintas, ambas necesitan reenvío:
     *
     *  - **sin entregar**: el correo nunca salió (fallo del mailer).
     *  - **sin contraseña**: salió, pero el dueño nunca la creó — abrió el
     *    enlace y lo dejó, o ni lo abrió. Es el agujero real del embudo, y sin
     *    mirarlo no se ve: el negocio está pagado y su dueño no puede entrar.
     *
     * @return array<array<string,mixed>>
     */
    private function pending(): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT b.slug, b.name, u.email, s.status, b.created_at,
                   CASE WHEN b.welcome_email_sent_at IS NULL
                        THEN 'sin entregar'
                        ELSE 'sin contraseña'
                   END AS reason
            FROM business b
            JOIN business_subscriptions s ON s.business_id = b.id
            LEFT JOIN users u ON u.id = b.creator_id
            WHERE b.deleted_at IS NULL
              -- Sólo a quien ha completado el alta: quien todavía no ha pagado
              -- no debe recibir una bienvenida.
              AND s.status <> 'pending_payment'
              AND (
                    b.welcome_email_sent_at IS NULL
                 OR (
                        -- Sólo cuenta como «sin contraseña» quien pasó por este
                        -- flujo, y eso lo delata tener algún token emitido. Los
                        -- 448 importados llegaron de Firebase con su contraseña
                        -- y nunca tuvieron ninguno: sin esta condición saldrían
                        -- todos, y un `--all` les escribiría a direcciones
                        -- reales sin motivo.
                        EXISTS (
                            SELECT 1 FROM password_setup_tokens t
                            WHERE t.user_id = b.creator_id
                        )
                        AND NOT EXISTS (
                            SELECT 1 FROM password_setup_tokens t
                            WHERE t.user_id = b.creator_id AND t.used_at IS NOT NULL
                        )
                    )
              )
            ORDER BY b.created_at
        SQL);
    }
}
