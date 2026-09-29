<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Command;

use App\Business\Infrastructure\Geocoding\GooglePostalCodeLookup;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Guarda el código postal de cada negocio en `meta.postal_code`.
 *
 * No había dónde: sólo venía, a veces, dentro del texto de la dirección. Se
 * saca de ahí cuando está —sin llamar a nadie— y, si no, se le pregunta a
 * Google por el punto del mapa y después por la dirección
 * (`GooglePostalCodeLookup`). Lo usa el Excel de `goveo:export:directory`.
 *
 * **A mano y no en el despliegue**, como la ciudad: son llamadas a una API de
 * pago. **Idempotente**: sólo mira a quien no lo tiene; `--force` lo recalcula
 * a todos. Cambiar la dirección de un negocio lo borra (`MyBusinessController`),
 * y volver a pasar esto lo rellena con la nueva.
 */
#[AsCommand(
    name: 'goveo:business:backfill-postal-codes',
    description: 'Rellena el código postal de los negocios (de la dirección o por Google).',
)]
final class BackfillBusinessPostalCodesCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly GooglePostalCodeLookup $postalCodes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Consulta y enseña, sin escribir')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Recalcula también los que ya lo tienen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force  = (bool) $input->getOption('force');

        $io->title('Código postal de los negocios');
        if ($dryRun) {
            $io->note('DRY RUN: no se escribe nada');
        }

        // Los borrados también, como la ciudad: uno recuperado vuelve completo.
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, name, meta::jsonb->>'address' AS address,
                    ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng
               FROM business"
            . ($force ? '' : " WHERE COALESCE(meta::jsonb->>'postal_code', '') = ''")
            . ' ORDER BY created_at',
        );

        $io->writeln(sprintf('  Por resolver: <info>%d</info>', count($rows)));
        if ($rows === []) {
            $io->success('Nada que hacer.');

            return Command::SUCCESS;
        }

        $fromAddress = 0;
        $fromGoogle  = 0;
        $missing     = [];

        foreach ($rows as $row) {
            $address = trim((string) $row['address']);

            if (preg_match('/\b(\d{5})\b/', $address, $m)) {
                $code = $m[1];
                ++$fromAddress;
            } else {
                $code = $this->postalCodes->postalCodeFor(
                    $row['lat'] !== null ? (float) $row['lat'] : null,
                    $row['lng'] !== null ? (float) $row['lng'] : null,
                    $address,
                );
                if ($code === null) {
                    $missing[] = sprintf('%s (%s)', $row['name'], $address ?: 'sin dirección');
                    continue;
                }
                ++$fromGoogle;
                if ($output->isVerbose() || $dryRun) {
                    $io->writeln(sprintf('  %s → <info>%s</info>', $row['name'], $code));
                }
            }

            if (!$dryRun) {
                // Por SQL, como la ciudad: no es un cambio de la ficha y no debe
                // mover `updated_at`. Sólo esa clave; el resto del `meta` no se toca.
                $this->db->executeStatement(
                    "UPDATE business
                        SET meta = jsonb_set(COALESCE(meta::jsonb, '{}'::jsonb), '{postal_code}', to_jsonb(?::text))::json
                      WHERE id = ?",
                    [$code, $row['id']],
                );
            }
        }

        if ($missing !== []) {
            $io->warning(array_merge(
                [sprintf('%d sin código. Si son muchos, mira la clave (GOOGLE_MAPS_API_KEY) o la cuota en el log:', count($missing))],
                array_slice($missing, 0, 20),
            ));
        }

        $io->success(sprintf(
            '%d de la dirección y %d de Google%s.',
            $fromAddress,
            $fromGoogle,
            $dryRun ? ' (simulado)' : '',
        ));

        return Command::SUCCESS;
    }
}
