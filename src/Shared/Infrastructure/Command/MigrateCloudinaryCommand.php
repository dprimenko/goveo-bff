<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Command;

use App\Shared\Infrastructure\Storage\BunnyStorageService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Traslada a Bunny todo lo que siga alojado en Cloudinary.
 *
 * Cloudinary es la última dependencia heredada: mientras haya una sola URL suya
 * en la base, cerrar esa cuenta rompe imágenes en la app y en la web.
 *
 * Descarga cada fichero, lo sube a su ruta en Bunny y reescribe la URL en la
 * misma fila. **Es idempotente**: lo que ya apunta a Bunny se salta, así que
 * puede interrumpirse y repetirse sin duplicar nada — necesario cuando son casi
 * dieciocho mil ficheros y algo va a fallar por el camino.
 */
#[AsCommand(
    name: 'goveo:media:migrate-cloudinary',
    description: 'Mueve las imágenes de Cloudinary a Bunny y reescribe las URLs.',
)]
final class MigrateCloudinaryCommand extends Command
{
    /**
     * Qué migrar y a dónde.
     *
     * `kind` describe la forma del dato, que no siempre es una URL suelta:
     *   string      la columna es la URL
     *   json_list   lista de objetos con clave `url` (products.images)
     *   json_map    objeto donde cualquier valor puede ser una URL (partners.meta)
     */
    private const TARGETS = [
        ['table' => 'business',              'column' => 'avatar',        'kind' => 'string',    'folder' => 'business',      'slot' => 'avatar'],
        ['table' => 'business',              'column' => 'main_image',    'kind' => 'string',    'folder' => 'business',      'slot' => 'main_image'],
        ['table' => 'influencers',           'column' => 'avatar',        'kind' => 'string',    'folder' => 'influencers',   'slot' => 'avatar'],
        ['table' => 'categories',            'column' => 'image',         'kind' => 'string',    'folder' => 'categories',    'slot' => 'image'],
        ['table' => 'default_subcategories', 'column' => 'icon',          'kind' => 'string',    'folder' => 'subcategories', 'slot' => 'icon'],
        ['table' => 'geostories',            'column' => 'thumbnail',     'kind' => 'string',    'folder' => 'geostories',    'slot' => 'thumbnail'],
        ['table' => 'users',                 'column' => 'profile_image', 'kind' => 'string',    'folder' => 'users',         'slot' => 'profile_image'],
        ['table' => 'partners',              'column' => 'meta',          'kind' => 'json_map',  'folder' => 'partners',      'slot' => null],
        ['table' => 'products',              'column' => 'images',        'kind' => 'json_list', 'folder' => null,            'slot' => null],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly BunnyStorageService $storage,
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    /** @var array<string,array{count:int,sample:string}> motivo => veces y ejemplo */
    private array $failures = [];

    private ?SymfonyStyle $io = null;

    /** Cuántas imágenes ya no existen en origen (404). */
    private int $missing = 0;

    /**
     * Deja constancia de un fallo: una línea al momento y un recuento al final.
     *
     * La línea sirve para verlo mientras corre —con miles de ficheros no vas a
     * esperar al resumen para saber si algo va mal—, y el recuento para no tener
     * que leerlas todas cuando el mismo motivo se repite mil veces.
     */
    private function note(string $reason, string $url, string $rowId = ''): void
    {
        if (!isset($this->failures[$reason])) {
            $this->failures[$reason] = ['count' => 0, 'sample' => $url];
        }
        ++$this->failures[$reason]['count'];

        // El id de la fila va delante porque es por donde se busca en la base
        // si hay que revisar ese registro a mano.
        $this->io?->writeln(sprintf(
            '  <fg=red>✗</> %s%s — %s',
            $rowId !== '' ? "[$rowId] " : '',
            $reason,
            $url,
        ));
    }

    /** Primera línea del mensaje: las trazas completas aquí sólo estorban. */
    private function reason(\Throwable $e): string
    {
        return substr(strtok($e->getMessage(), "\n") ?: get_class($e), 0, 120);
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo cuenta e informa, no descarga ni escribe');
        $this->addOption('table', null, InputOption::VALUE_REQUIRED, 'Migra sólo esta tabla');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de filas por tabla (para probar)');
        $this->addOption(
            'retire-missing', null, InputOption::VALUE_NONE,
            'Da de baja los productos cuyas imágenes hayan desaparecido de Cloudinary (404). '
            .'Es baja lógica: se marca deleted_at y se puede revertir.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $this->io = $io;
        $dryRun   = (bool) $input->getOption('dry-run');
        $only    = $input->getOption('table');
        $limit   = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $retireMissing = (bool) $input->getOption('retire-missing');

        $io->title('Cloudinary → Bunny');

        if (!$dryRun && !$this->storage->isConfigured()) {
            $io->error('Bunny no está configurado: revisa BUNNY_STORAGE_*.');

            return Command::FAILURE;
        }

        $totals = ['filas' => 0, 'ficheros' => 0, 'movidos' => 0, 'fallidos' => 0];

        foreach (self::TARGETS as $target) {
            if ($only !== null && $target['table'] !== $only) {
                continue;
            }

            [$rows, $files, $moved, $failed] = $this->migrateTarget($io, $target, $dryRun, $limit, $retireMissing);

            $totals['filas']    += $rows;
            $totals['ficheros'] += $files;
            $totals['movidos']  += $moved;
            $totals['fallidos'] += $failed;
        }

        $io->section('Total');
        $io->listing([
            sprintf('filas con Cloudinary: %d', $totals['filas']),
            sprintf('ficheros afectados: %d', $totals['ficheros']),
            $dryRun
                ? 'en seco: no se ha movido nada'
                : sprintf('movidos: %d · fallidos: %d', $totals['movidos'], $totals['fallidos']),
        ]);

        if ($this->failures !== []) {
            $io->section('Por qué fallaron');
            $rows = [];
            foreach ($this->failures as $reason => $data) {
                $rows[] = [$data['count'], $reason, substr($data['sample'], 0, 60) . '…'];
            }
            usort($rows, static fn ($a, $b) => $b[0] <=> $a[0]);
            $io->table(['veces', 'motivo', 'ejemplo'], $rows);
        }

        if (!$dryRun && $totals['fallidos'] > 0) {
            $io->warning(sprintf(
                '%d ficheros no se pudieron mover y conservan su URL de Cloudinary. '
                .'Repite el comando: los ya migrados se saltan.',
                $totals['fallidos'],
            ));
        }

        return Command::SUCCESS;
    }

    /** @return array{0:int,1:int,2:int,3:int} filas, ficheros, movidos, fallidos */
    private function migrateTarget(
        SymfonyStyle $io,
        array $target,
        bool $dryRun,
        ?int $limit,
        bool $retireMissing = false,
    ): array {
        $table  = $target['table'];
        $column = $target['column'];

        $sql = sprintf(
            'SELECT id, %s AS value%s FROM %s WHERE %s::text LIKE :needle ORDER BY id',
            $column,
            $table === 'products' ? ', business_id' : '',
            $table,
            $column,
        );
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        $rows = $this->connection->fetchAllAssociative($sql, ['needle' => '%cloudinary%']);
        if ($rows === []) {
            return [0, 0, 0, 0];
        }

        $files   = 0;
        $moved   = 0;
        $failed  = 0;
        $retired = 0;

        $io->section(sprintf('%s.%s — %d filas', $table, $column, count($rows)));
        // Sin barra de progreso: se solapa con las líneas de error y deja la
        // salida ilegible justo cuando hay algo que leer.
        if (!$dryRun) {
            $io->text(sprintf('  procesando %d filas…', count($rows)));
        }

        foreach ($rows as $row) {
            $urls = $this->extractUrls($row['value'], $target['kind']);
            $files += count($urls);

            if ($dryRun) {
                continue;
            }

            $replacements = [];
            $gone         = 0;
            foreach ($urls as $index => $url) {
                $before = $this->missing;
                $newUrl = $this->move($url, $target, $row, $index);
                if ($newUrl !== null) {
                    $replacements[$url] = $newUrl;
                    ++$moved;
                } else {
                    ++$failed;
                    // Se cuenta aparte lo que ya no existe en origen: es lo
                    // único que justifica dar de baja el producto.
                    if ($this->missing > $before) {
                        ++$gone;
                    }
                }
            }

            // Sólo si han desaparecido **todas**: un producto con cuatro fotos y
            // una muerta sigue siendo un producto bueno.
            if ($retireMissing
                && $table === 'products'
                && $gone > 0
                && $gone === count($urls)
                && $replacements === []
            ) {
                $this->connection->executeStatement(
                    'UPDATE products SET deleted_at = now() WHERE id = :id AND deleted_at IS NULL',
                    ['id' => $row['id']],
                );
                ++$retired;
            }

            if ($replacements !== []) {
                $this->connection->executeStatement(
                    sprintf('UPDATE %s SET %s = :value WHERE id = :id', $table, $column),
                    [
                        'value' => strtr((string) $row['value'], $replacements),
                        'id'    => $row['id'],
                    ],
                );
            }

        }

        $io->newLine(2);

        if ($dryRun) {
            $io->text(sprintf('  %d ficheros', $files));
        }

        if ($retired > 0) {
            $io->warning(sprintf(
                '%d productos dados de baja: todas sus imágenes habían desaparecido de Cloudinary.',
                $retired,
            ));
        }

        return [count($rows), $files, $moved, $failed];
    }

    /**
     * Saca las URLs de Cloudinary de un valor, sea del formato que sea.
     *
     * Se busca por expresión regular sobre el texto crudo en lugar de decodificar
     * el JSON: los formatos varían entre tablas (lista de objetos, mapa de claves
     * arbitrarias) y lo único constante es la forma de la URL. Y el reemplazo
     * posterior es textual, así que la estructura se conserva intacta.
     *
     * @return list<string>
     */
    private function extractUrls(mixed $value, string $kind): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        // En JSON las barras vienen escapadas (`https:\/\/…`).
        $plain = str_replace('\\/', '/', $value);

        preg_match_all('#https://res\.cloudinary\.com/[^"\'\\\\\s,}\]]+#i', $plain, $matches);

        $urls = array_values(array_unique($matches[0]));

        // Se devuelven tal y como aparecen en el original, para que el reemplazo
        // textual encaje aunque el JSON lleve las barras escapadas.
        return array_map(
            static fn (string $u) => str_contains($value, $u) ? $u : str_replace('/', '\\/', $u),
            $urls,
        );
    }

    /**
     * @return string|null la URL nueva, o null si no se pudo mover
     *
     * Los motivos se acumulan en `$this->failures` en vez de descartarse: saber
     * que fallaron mil ficheros sin saber por qué no sirve de nada, y la causa
     * suele ser una sola —una imagen borrada en origen, un límite de peticiones,
     * una credencial caducada— repetida mil veces.
     */
    private function move(string $url, array $target, array $row, int $index): ?string
    {
        $clean = str_replace('\\/', '/', $url);

        try {
            $response = $this->httpClient->request('GET', $clean, ['timeout' => 30]);
            $status   = $response->getStatusCode();
            if ($status >= 300) {
                if ($status === 404) {
                    ++$this->missing;
                }
                $this->note(sprintf('descarga HTTP %d', $status), $clean, (string) ($row['id'] ?? ''));

                return null;
            }
            $contents = $response->getContent();
        } catch (\Throwable $e) {
            $this->note('descarga: ' . $this->reason($e), $clean, (string) ($row['id'] ?? ''));

            return null;
        }

        try {
            $newUrl = $this->storage->upload(
                fn (string $ext) => $this->pathFor($target, $row, $index, $ext),
                $contents,
                // Sin límite de tamaño: son imágenes que ya existían, y el tope
                // de 8 MB está pensado para subidas de usuario. El Optimizer las
                // sirve reducidas de todas formas.
                enforceSizeLimit: false,
            );
        } catch (\Throwable $e) {
            $this->note('subida: ' . $this->reason($e), $clean, (string) ($row['id'] ?? ''));

            return null;
        }

        // Si la URL original venía escapada, la nueva también debe irlo: el
        // reemplazo es textual sobre el JSON.
        return str_contains($url, '\\/') ? str_replace('/', '\\/', $newUrl) : $newUrl;
    }

    private function pathFor(array $target, array $row, int $index, string $ext): string
    {
        // Los productos cuelgan de su negocio: así todo lo de un cliente vive
        // junto y borrarlo es una sola carpeta.
        if ($target['table'] === 'products') {
            return sprintf(
                'business/%s/products/%s/%d-%d.%s',
                $row['business_id'] ?? 'sin-negocio',
                $row['id'],
                $index,
                time(),
                $ext,
            );
        }

        $slot = $target['slot'] ?? sprintf('%d', $index);

        return sprintf('%s/%s/%s-%d.%s', $target['folder'], $row['id'], $slot, time(), $ext);
    }
}
