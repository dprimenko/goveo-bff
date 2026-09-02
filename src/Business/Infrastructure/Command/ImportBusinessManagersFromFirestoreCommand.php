<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Command;

use App\Shared\Infrastructure\Command\AbstractSupabaseMigrationCommand;
use App\Shared\Infrastructure\Firebase\FirestoreClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Quién gestiona qué negocio, según Firestore (`users.managerOf`).
 *
 * El import desde Supabase trajo 211 vínculos, pero en la app de Flutter la
 * gestión no salía de ahí: salía de un array `managerOf` en el documento del
 * usuario. Lo que no estuviera además en Supabase se quedó fuera, y con ello
 * cuentas que gestionaban su tienda desde siempre y aquí aparecen sin nada —
 * la app les ofrece «¿Tienes un negocio?» y no pueden editar lo suyo.
 *
 * **El usuario se busca por correo, no por id.** El documento de Firestore lleva
 * el UID de Firebase, que no es el `users.id` de aquí; el correo es lo único que
 * hay a los dos lados. El negocio sí va por id: el mismo UUID v5 con el que se
 * importó todo lo demás, así que el id de la tienda en Firestore lleva al
 * registro correcto sin tener que mirar nada más.
 *
 * Sólo inserta. Los vínculos que existen aquí y no en Firestore —los que se
 * crean al dar de alta un negocio desde la web— se quedan como están.
 *
 * Usage:
 *   php bin/console goveo:migrate:firestore:business-managers --dry-run
 *   php bin/console goveo:migrate:firestore:business-managers
 *   php bin/console goveo:migrate:firestore:business-managers --email=alguien@ejemplo.com
 */
#[AsCommand(
    name: 'goveo:migrate:firestore:business-managers',
    description: 'Importa users.managerOf de Firestore a business_managers.',
)]
final class ImportBusinessManagersFromFirestoreCommand extends AbstractSupabaseMigrationCommand
{
    public function __construct(
        private readonly FirestoreClientFactory $firestore,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría, sin escribir');
        $this->addOption('email', null, InputOption::VALUE_REQUIRED, 'Sólo esa cuenta');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $sólo   = $input->getOption('email');
        $sólo   = is_string($sólo) ? strtolower(trim($sólo)) : null;

        $io->title('Firestore users.managerOf → business_managers');
        if ($dryRun) {
            $io->note('EN SECO: no se escribe nada');
        }
        if ($sólo !== null) {
            $io->note("Sólo: $sólo");
        }

        $db  = $this->em->getConnection();
        $col = $this->firestore->create()->collection('users');

        $creados = $yaEstaban = 0;
        $sinUsuario = $sinNegocio = 0;
        $porCuenta = [];

        foreach ($col->documents() as $doc) {
            if (!$doc->exists()) {
                continue;
            }

            $data  = $doc->data();
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            $stores = $data['managerOf'] ?? null;

            if ($email === '' || !is_array($stores) || $stores === []) {
                continue;
            }
            if ($sólo !== null && $email !== $sólo) {
                continue;
            }

            $userId = $db->fetchOne('SELECT id FROM users WHERE lower(email) = ?', [$email]);
            if ($userId === false) {
                // La cuenta nunca llegó a migrarse. No se inventa: sin usuario
                // el vínculo no tiene a quién colgarse.
                ++$sinUsuario;
                continue;
            }

            foreach ($stores as $storeId) {
                if (!is_string($storeId) || $storeId === '') {
                    continue;
                }

                $businessId = $this->toUuid($storeId);

                // El negocio tiene que existir y estar vivo: en Firestore quedan
                // referencias a tiendas que se dieron de baja, y meterlas aquí
                // llenaría la lista del gestor de fichas que no puede abrir.
                $existe = (bool) $db->fetchOne(
                    'SELECT 1 FROM business WHERE id = ? AND deleted_at IS NULL',
                    [$businessId]
                );
                if (!$existe) {
                    ++$sinNegocio;
                    continue;
                }

                $yaEsta = (bool) $db->fetchOne(
                    'SELECT 1 FROM business_managers WHERE user_id = ? AND business_id = ?',
                    [$userId, $businessId]
                );
                if ($yaEsta) {
                    ++$yaEstaban;
                    continue;
                }

                if (!$dryRun) {
                    $db->executeStatement(
                        'INSERT INTO business_managers (user_id, business_id)
                         VALUES (?, ?) ON CONFLICT (user_id, business_id) DO NOTHING',
                        [$userId, $businessId]
                    );
                }

                ++$creados;
                $porCuenta[$email] = ($porCuenta[$email] ?? 0) + 1;
            }
        }

        arsort($porCuenta);
        $io->section($dryRun ? 'Se crearían' : 'Creados');
        foreach (array_slice($porCuenta, 0, 15, true) as $email => $n) {
            $io->writeln(sprintf('  %-38s %d', $email, $n));
        }
        if (count($porCuenta) > 15) {
            $io->writeln(sprintf('  … y %d cuentas más', count($porCuenta) - 15));
        }

        $io->success(sprintf(
            '%s: %d · ya estaban: %d · sin negocio vivo: %d · sin usuario: %d',
            $dryRun ? 'Se crearían' : 'Creados',
            $creados,
            $yaEstaban,
            $sinNegocio,
            $sinUsuario,
        ));

        return Command::SUCCESS;
    }
}
