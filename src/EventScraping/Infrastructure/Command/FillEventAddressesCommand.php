<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pone la dirección a los eventos importados que no la tienen.
 *
 *     php bin/console goveo:events:fill-addresses              # enseña qué pondría
 *     php bin/console goveo:events:fill-addresses --apply      # la guarda
 *     php bin/console goveo:events:fill-addresses --all-owners # también los de una sala
 *
 * Hasta el 27-09-2026 el scraping guardaba las coordenadas del evento pero no su
 * dirección (`meta.address`). La app, sin ella, tira de la del negocio dueño, y
 * los de la **Agenda Goveo** no tienen negocio: la tarjeta decía «España» y
 * «Cómo llegar» no abría nada. Lo que se importe a partir de ahora ya la lleva,
 * y una pasada nueva completa lo que siga en la web de la fuente; esto es para
 * lo que ya no esté.
 *
 * La dirección sale de las **coordenadas de cada evento**, preguntándole a la
 * geocodificación inversa de Google: cada uno está en su sitio, y el punto es
 * el dato fiable que se guardó. Sin `--all-owners` sólo toca los de la Agenda:
 * los de una sala ya enseñan la de la sala.
 */
#[AsCommand(
    name: 'goveo:events:fill-addresses',
    description: 'Pone la dirección (por sus coordenadas) a los eventos importados que no la tienen.',
)]
final class FillEventAddressesCommand extends Command
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    public function __construct(
        private readonly Connection $db,
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Guarda las direcciones; sin esto sólo las enseña.')
            ->addOption('all-owners', null, InputOption::VALUE_NONE, 'También los eventos que cuelgan de una sala, no sólo los de la Agenda.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        if ($this->apiKey === '') {
            $io->error('Falta GOOGLE_MAPS_API_KEY: sin ella no hay de dónde sacar las direcciones.');

            return Command::FAILURE;
        }

        $owner = $input->getOption('all-owners') ? '' : 'AND g.influencer_id IS NOT NULL AND g.business_id IS NULL';
        $rows  = $this->db->fetchAllAssociative(
            "SELECT g.id, g.title,
                    ST_Y(g.location::geometry) AS lat,
                    ST_X(g.location::geometry) AS lng
               FROM geostories g
              WHERE g.external_ref IS NOT NULL
                AND g.deleted_at IS NULL
                AND g.location IS NOT NULL
                AND COALESCE(g.meta->>'address', '') = ''
                {$owner}
              ORDER BY g.created_at",
        );

        if ($rows === []) {
            $io->success('No hay eventos sin dirección.');

            return Command::SUCCESS;
        }

        $io->text(sprintf('%d eventos sin dirección%s.', count($rows), $apply ? '' : ' (en seco: no se guarda nada)'));

        // Muchos eventos comparten sitio (una sala con varias fechas): se
        // pregunta una vez por punto.
        $cache  = [];
        $filled = 0;
        $missed = 0;

        foreach ($rows as $row) {
            $key = sprintf('%.6f,%.6f', (float) $row['lat'], (float) $row['lng']);
            if (!array_key_exists($key, $cache)) {
                $cache[$key] = $this->addressAt((float) $row['lat'], (float) $row['lng'], $io);
            }
            $address = $cache[$key];

            if ($address === null) {
                ++$missed;
                $io->text(sprintf('  ✗ %s', $row['title']));
                continue;
            }

            $io->text(sprintf('  ✓ %s → %s', $row['title'], $address));
            ++$filled;

            if ($apply) {
                $this->db->executeStatement(
                    "UPDATE geostories
                        SET meta = jsonb_set(COALESCE(meta::jsonb, '{}'::jsonb), '{address}', to_jsonb(?::text))::json,
                            updated_at = NOW()
                      WHERE id = ? AND COALESCE(meta->>'address', '') = ''",
                    [$address, $row['id']],
                );
            }
        }

        $io->newLine();
        $io->success(sprintf(
            '%s %d · sin dirección en Google: %d · consultas: %d',
            $apply ? 'Guardadas:' : 'Se guardarían:',
            $filled,
            $missed,
            count($cache),
        ));

        return Command::SUCCESS;
    }

    /** La dirección de ese punto, en español, o `null` si Google no la da. */
    private function addressAt(float $lat, float $lng, SymfonyStyle $io): ?string
    {
        try {
            $data = $this->http->request('GET', self::ENDPOINT, [
                'query' => [
                    'latlng'   => sprintf('%.7f,%.7f', $lat, $lng),
                    'language' => 'es',
                    'key'      => $this->apiKey,
                ],
                'timeout' => 10,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $io->warning('Google no responde: ' . $e->getMessage());

            return null;
        }

        $status = (string) ($data['status'] ?? '');
        if ($status !== 'OK') {
            // Clave mal o cuota agotada: que se vea, o todo saldría «sin
            // dirección» sin saber por qué.
            if ($status !== 'ZERO_RESULTS') {
                $io->warning(sprintf('Google: %s %s', $status, $data['error_message'] ?? ''));
            }

            return null;
        }

        // El primero es el más concreto (el portal). «, España» sobra: todo
        // está aquí, y la tarjeta enseña el primer trozo igualmente.
        $address = (string) ($data['results'][0]['formatted_address'] ?? '');
        $address = trim((string) preg_replace('/,\s*España$/u', '', $address));

        return $address !== '' ? mb_substr($address, 0, 255) : null;
    }
}
