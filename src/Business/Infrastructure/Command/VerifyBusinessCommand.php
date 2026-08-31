<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Command;

use App\Business\Domain\Business;
use App\Business\Domain\BusinessRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Valida (o retira la validación de) un negocio, a mano, mientras no hay panel.
 *
 * `business.verified_at` es lo único que decide si un negocio se publica: con
 * la fecha puesta sale en el feed, el mapa y la búsqueda; sin ella sólo lo ve su
 * dueño en «Gestión de negocios», marcado como pendiente.
 *
 *   php bin/console goveo:business:verify                     # lista pendientes
 *   php bin/console goveo:business:verify bar-manolo          # valida uno
 *   php bin/console goveo:business:verify bar-manolo --revoke # lo retira
 */
#[AsCommand(
    name: 'goveo:business:verify',
    description: 'Valida un negocio para que se publique, o lista los pendientes.',
)]
final class VerifyBusinessCommand extends Command
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('business', InputArgument::OPTIONAL, 'Slug o id. Sin él, lista los pendientes.');
        $this->addOption('revoke', null, InputOption::VALUE_NONE, 'Retira la validación en vez de darla');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Valida todos los pendientes de golpe');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Con --all, no pide confirmación (para ejecuciones sin terminal)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $ref     = $input->getArgument('business');
        $revoke  = (bool) $input->getOption('revoke');

        if ($input->getOption('all')) {
            return $this->verifyAll($io, (bool) $input->getOption('force'));
        }

        if ($ref === null) {
            return $this->listPending($io);
        }

        $business = $this->businesses->findBySlug($ref) ?? $this->businesses->findById($ref);
        if ($business === null) {
            $io->error(sprintf('No hay ningún negocio con slug o id «%s».', $ref));

            return Command::FAILURE;
        }

        if ($revoke) {
            $business->unverify();
            $this->businesses->save($business);
            $io->warning(sprintf('«%s» deja de publicarse.', $business->getName()));

            return Command::SUCCESS;
        }

        if ($business->isVerified()) {
            $io->note(sprintf('«%s» ya estaba validado.', $business->getName()));

            return Command::SUCCESS;
        }

        $business->verify();
        $this->businesses->save($business);
        $io->success(sprintf('«%s» validado: ya sale en el feed, el mapa y la búsqueda.', $business->getName()));

        return Command::SUCCESS;
    }

    private function listPending(SymfonyStyle $io): int
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT b.slug, b.name, u.email AS owner, b.created_at,
                   COALESCE(p.name, '—') AS plan,
                   COALESCE(s.status, '—') AS subscription
            FROM business b
            LEFT JOIN users u ON u.id = b.creator_id
            LEFT JOIN business_subscriptions s ON s.business_id = b.id
            LEFT JOIN billing_plans p ON p.id = s.billing_plan_id
            WHERE b.deleted_at IS NULL AND b.verified_at IS NULL
            ORDER BY b.created_at
        SQL);

        if ($rows === []) {
            $io->success('No hay negocios pendientes de validar.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('%d negocio(s) pendiente(s) de validar', count($rows)));
        $io->table(
            ['Slug', 'Nombre', 'Dueño', 'Tarifa', 'Suscripción', 'Alta'],
            array_map(static fn (array $r) => [
                $r['slug'],
                $r['name'],
                $r['owner'] ?? '—',
                $r['plan'],
                // Un `pending_payment` aquí significa que aún no ha pagado.
                $r['subscription'],
                substr((string) $r['created_at'], 0, 10),
            ], $rows),
        );
        $io->writeln('  Para validar: <info>php bin/console goveo:business:verify <slug></info>');

        return Command::SUCCESS;
    }

    private function verifyAll(SymfonyStyle $io, bool $force = false): int
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM business WHERE deleted_at IS NULL AND verified_at IS NULL',
        );

        if ($ids === []) {
            $io->success('No hay nada pendiente.');

            return Command::SUCCESS;
        }

        // Sin terminal —`docker exec` sin `-it`, un cron— `confirm()` devuelve
        // el valor por defecto y el comando no haría nada, aparentando haber
        // funcionado. Mejor exigir la bandera de forma explícita.
        if (!$force && !$io->isInteractive()) {
            $io->error(sprintf(
                'Hay %d negocio(s) pendientes, pero no hay terminal para confirmar. Repite con --force.',
                count($ids),
            ));

            return Command::FAILURE;
        }

        if (!$force && !$io->confirm(sprintf('¿Validar %d negocio(s) sin revisarlos uno a uno?', count($ids)), false)) {
            return Command::SUCCESS;
        }

        foreach ($ids as $id) {
            $business = $this->businesses->findById($id);
            if ($business instanceof Business) {
                $business->verify();
                $this->businesses->save($business);
            }
        }

        $io->success(sprintf('%d negocios validados.', count($ids)));

        return Command::SUCCESS;
    }
}
