<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Command;

use App\Shared\Infrastructure\Export\XlsxWriter;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Negocios e influencers en un Excel, con el enlace para compartir cada perfil.
 *
 * Para la gestión comercial: a quién escribir y qué enlace pasarle. Dos hojas:
 *
 * - **Negocios** (no borrados): nombre, email, teléfono, enlace, código postal,
 *   categoría, dirección, ciudad y si está validado.
 * - **Influencers** (no borrados): nombre visible, usuario, email y enlace.
 *
 * **El enlace es el mismo que comparte la app**: uno corto de Branch con
 * `goveo_kind`/`goveo_id`, que abre el perfil en la app o, sin ella, en la web.
 * Se piden a la API masiva de Branch de cien en cien; si falla, esa tanda va
 * con el de la web (`/b/{id}`, `/i/{id}`), que también sirve. Branch devuelve
 * el mismo enlace para los mismos datos, así que repetir el export no los
 * multiplica.
 *
 * **Email del negocio**: el de las cuentas que lo gestionan (varias, separadas
 * por comas) y, si no tiene ninguna, el de facturación. **Sin las del equipo**
 * (`--exclude`, por defecto las tres que gestionan casi todos los negocios) ni
 * las `@goveo.app`/`@goveo.test`, que son relleno de la importación y no llegan
 * a nadie. No hay un email público
 * de la ficha. **Código postal**: sale de la dirección (el primer número de
 * cinco cifras); no se guarda aparte.
 *
 *   goveo:export:directory                       # /tmp/goveo-directorio.xlsx
 *   goveo:export:directory --output=/tmp/x.xlsx
 *   goveo:export:directory --no-branch           # enlaces de la web, sin llamar a Branch
 */
#[AsCommand(
    name: 'goveo:export:directory',
    description: 'Excel de negocios e influencers con su enlace para compartir.',
)]
final class ExportDirectoryCommand extends Command
{
    /** Publicable: es la misma que va dentro de la app y de la web. */
    private const BRANCH_KEY  = 'key_live_boNGWRXDcNVejIKYS6mAdfncEEeztQ1P';
    private const BRANCH_BULK = 'https://api2.branch.io/v1/url/bulk/';
    /** Las cuentas del equipo: están de gestoras en casi todos los negocios. */
    private const TEAM_EMAILS = ['goveoapp@gmail.com', 'globalydigitale@gmail.com', 'davidprimenko@gmail.com'];

    /** El tope de la API masiva de Branch. */
    private const BRANCH_BATCH = 100;

    /**
     * Las categorías que guardan una clave de traducción (`category.fashion`)
     * en vez del nombre. Los mismos textos que la app y el panel
     * (`goveo-backoffice/src/i18n/categories.ts`). Las que no están aquí salen
     * con su nombre, o con el slug si el nombre es una clave.
     */
    private const CATEGORY_NAMES = [
        'accesories'          => 'Accesorios',
        'accommodation-ibiza' => 'Alojamiento',
        'baby'                => 'Bebés',
        'beach-restaurant'    => 'Restaurante de playa',
        'beauty'              => 'Belleza',
        'boats'               => 'Barcos',
        'bookshop'            => 'Librería',
        'car-rental'          => 'Alquiler de coches',
        'crafts'              => 'Artesanía',
        'diy'                 => 'Bricolaje',
        'excursions'          => 'Excursiones',
        'experiences'         => 'Experiencias',
        'fashion'             => 'Moda',
        'food-retail'         => 'Alimentación',
        'footwear'            => 'Calzado',
        'gifts'               => 'Regalos',
        'health'              => 'Salud',
        'home_appliances'     => 'Electrodomésticos',
        'home_decoration'     => 'Decoración',
        'jewelry'             => 'Joyería',
        'luxury-restaurant'   => 'Restaurante de lujo',
        'misc'                => 'Varios',
        'nightlife'           => 'Vida Nocturna',
        'party'               => 'Fiesta',
        'pets'                => 'Mascotas',
        'place'               => 'Lugares',
        'services'            => 'Servicios',
        'shopping'            => 'Compras',
        'sport'               => 'Deporte',
        'technology'          => 'Tecnología',
        'toys'                => 'Juguetes',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly HttpClientInterface $http,
        private readonly string $webUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Dónde dejar el fichero.', '/tmp/goveo-directorio.xlsx');
        $this->addOption('no-branch', null, InputOption::VALUE_NONE, 'Enlaces de la web, sin pedir los de Branch.');
        $this->addOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Emails que no salen (los del equipo).', self::TEAM_EMAILS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $path     = (string) $input->getOption('output');
        $noBranch = (bool) $input->getOption('no-branch');
        $web      = rtrim($this->webUrl, '/');
        $exclude  = array_map('strtolower', (array) $input->getOption('exclude'));
        $contact  = static fn (?string $email): bool => $email !== null && $email !== ''
            && !in_array(strtolower($email), $exclude, true)
            && !preg_match('/@goveo\.(app|test)$/i', $email);

        $businesses = $this->db->fetchAllAssociative(
            "SELECT b.id, b.name, b.city, b.meta, b.verified_at, c.slug AS category_slug, c.name AS category_name,
                    (SELECT string_agg(DISTINCT u.email, ',')
                       FROM business_managers m JOIN users u ON u.id = m.user_id
                      WHERE m.business_id = b.id AND m.deleted_at IS NULL AND u.email IS NOT NULL) AS emails
               FROM business b
               LEFT JOIN categories c ON c.id = b.category_id
              WHERE b.deleted_at IS NULL
              ORDER BY lower(b.name), b.id",
        );
        $influencers = $this->db->fetchAllAssociative(
            'SELECT i.id, i.name, i.username, u.email
               FROM influencers i LEFT JOIN users u ON u.id = i.user_id
              WHERE i.deleted_at IS NULL
              ORDER BY lower(i.name), i.id',
        );
        $io->writeln(sprintf('%d negocios y %d influencers.', count($businesses), count($influencers)));

        $businessLinks = $this->links('business', $businesses, $web, $noBranch, $io);
        $influencerLinks = $this->links('influencer', $influencers, $web, $noBranch, $io);

        $businessRows = [];
        foreach ($businesses as $b) {
            $meta    = json_decode((string) ($b['meta'] ?? ''), true) ?: [];
            $billing = is_array($meta['billing'] ?? null) ? $meta['billing'] : [];
            $address = trim((string) ($meta['address'] ?? ''));

            $emails = array_values(array_filter(explode(',', (string) $b['emails']), $contact));
            $billingEmail = $billing['email'] ?? null;

            $businessRows[] = [
                trim((string) $b['name']),
                $emails !== [] ? implode(', ', $emails) : ($contact($billingEmail) ? $billingEmail : null),
                ($meta['public_phone'] ?? null) ?: ($billing['phone'] ?? null),
                $businessLinks[$b['id']],
                preg_match('/\b(\d{5})\b/', $address, $m) ? $m[1] : null,
                $this->category($b['category_slug'], $b['category_name']),
                $address ?: null,
                $b['city'],
                $b['verified_at'] !== null ? 'Sí' : 'No',
            ];
        }

        $influencerRows = [];
        foreach ($influencers as $i) {
            $influencerRows[] = [
                trim((string) $i['name']),
                '@' . $i['username'],
                $contact($i['email']) ? $i['email'] : null,
                $influencerLinks[$i['id']],
            ];
        }

        (new XlsxWriter())
            ->addSheet(
                'Negocios',
                ['Nombre', 'Email', 'Teléfono', 'Enlace', 'Código postal', 'Categoría', 'Dirección', 'Ciudad', 'Validado'],
                $businessRows,
                linkColumns: [3],
                widths: [36, 34, 16, 34, 13, 20, 50, 18, 10],
            )
            ->addSheet(
                'Influencers',
                ['Nombre visible', 'Usuario', 'Email', 'Enlace'],
                $influencerRows,
                linkColumns: [3],
                widths: [34, 28, 34, 34],
            )
            ->save($path);

        $io->success(sprintf('Guardado en %s', $path));

        return Command::SUCCESS;
    }

    /**
     * El enlace de cada perfil: de Branch, o el de la web si Branch falla.
     *
     * @param list<array<string, mixed>> $rows con `id` y `name`
     *
     * @return array<string, string> id → enlace
     */
    private function links(string $kind, array $rows, string $web, bool $noBranch, SymfonyStyle $io): array
    {
        $prefix = $kind === 'business' ? 'b' : 'i';
        $links  = [];
        foreach ($rows as $row) {
            $links[$row['id']] = sprintf('%s/%s/%s', $web, $prefix, $row['id']);
        }
        if ($noBranch || $rows === []) {
            return $links;
        }

        $failed = 0;
        foreach (array_chunk($rows, self::BRANCH_BATCH) as $batch) {
            $payload = array_map(function (array $row) use ($kind, $prefix, $links): array {
                $webUrl = $links[$row['id']];

                return [
                    // Para distinguir en la analítica de Branch los que reparte el equipo.
                    'channel' => 'export',
                    'feature' => $kind === 'business' ? 'store' : 'publisher',
                    'stage'   => 'profile',
                    'data'    => [
                        '$canonical_identifier' => sprintf('%s/%s', $prefix, $row['id']),
                        'goveo_kind'            => $kind,
                        'goveo_id'              => $row['id'],
                        '$desktop_url'          => $webUrl,
                        '$ios_url'              => $webUrl,
                        '$android_url'          => $webUrl,
                        '$og_title'             => $row['name'] ?: 'Goveo',
                    ],
                ];
            }, $batch);

            try {
                $response = $this->http->request('POST', self::BRANCH_BULK . self::BRANCH_KEY, ['json' => $payload]);
                $result   = $response->toArray();
            } catch (\Throwable $e) {
                $failed += count($batch);
                $io->warning(sprintf('Branch no ha respondido (%s): %d enlaces van con la web.', $e->getMessage(), count($batch)));
                continue;
            }

            foreach ($batch as $n => $row) {
                $url = $result[$n]['url'] ?? null;
                if (is_string($url) && $url !== '') {
                    // Su dominio por defecto no responde: se reparte el propio (ver la app).
                    $links[$row['id']] = (string) preg_replace(
                        '~^https://(goveo\.app\.link|goveo-alternate\.app\.link)/~',
                        'https://links.goveo.app/',
                        $url,
                    );
                } else {
                    $failed++;
                }
            }
        }

        if ($failed > 0) {
            $io->note(sprintf('%d enlaces de %s van con la web en vez de Branch.', $failed, $kind === 'business' ? 'negocios' : 'influencers'));
        }

        return $links;
    }

    private function category(?string $slug, ?string $name): ?string
    {
        if ($slug !== null && isset(self::CATEGORY_NAMES[$slug])) {
            return self::CATEGORY_NAMES[$slug];
        }
        if ($name !== null && !str_starts_with($name, 'category.')) {
            return $name;
        }

        return $slug;
    }
}
