<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Command;

use App\EventScraping\Infrastructure\PosterFrame;
use App\GeoStories\Domain\GeoStoryRepository;
use App\Shared\Infrastructure\Storage\BunnyStorageService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pone a una foto ya publicada el marco negro del scraping (ver `PosterFrame`).
 *
 *     php bin/console goveo:geostories:frame https://links.goveo.app/D6oMkWcNX6b          # dice qué haría
 *     php bin/console goveo:geostories:frame https://links.goveo.app/D6oMkWcNX6b --apply  # lo hace
 *     php bin/console goveo:geostories:frame <id> <otro enlace> … --apply
 *     php bin/console goveo:geostories:frame --all --apply   # todas las subidas desde la app
 *
 * Acepta el enlace de compartir de la app (Branch), uno de goveo.app o el id.
 *
 * `--all` es para la tarea de Dokploy, que no admite argumentos: repasa las
 * fotos subidas desde la app (las del scraping ya nacen enmarcadas) y anota en
 * cada una `meta.frame_checked_at`, para no volver a descargarla en la pasada
 * siguiente. En seco no anota nada.
 *
 * Desde el 04-10-2026 el BFF enmarca solo las fotos anchas al publicarlas; esto
 * es para las de antes. La app las pinta para llenar la pantalla y un cartel
 * cuadrado salía recortado por los lados y ampliado. Sólo toca fotos y sólo si
 * son más anchas que vertical: una foto vertical se deja como está.
 */
#[AsCommand(
    name: 'goveo:geostories:frame',
    description: 'Pone el marco negro (9:16) a fotos ya publicadas, por su enlace de compartir o su id.',
)]
final class FramePhotoCommand extends Command
{
    private const UUID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    public function __construct(
        private readonly Connection $db,
        private readonly GeoStoryRepository $geoStories,
        private readonly BunnyStorageService $storage,
        private readonly PosterFrame $frame,
        private readonly HttpClientInterface $http,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('refs', InputArgument::IS_ARRAY, 'Enlaces de compartir o ids de las publicaciones.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Todas las fotos subidas desde la app que no se hayan revisado ya.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Sube la foto enmarcada; sin esto sólo dice qué haría.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $fails = 0;
        $all   = (bool) $input->getOption('all');

        $refs = $all ? $this->pending() : (array) $input->getArgument('refs');
        if ($refs === []) {
            $io->text($all ? 'No hay fotos sin revisar.' : 'Pásale un enlace de compartir o un id, o --all.');

            return $all ? Command::SUCCESS : Command::INVALID;
        }

        foreach ($refs as $ref) {
            $id = $this->resolve((string) $ref);
            if ($id === null) {
                $io->text(sprintf('  ✗ %s — no encuentro ninguna publicación ahí', $ref));
                ++$fails;
                continue;
            }

            $story = $this->geoStories->findById($id);
            if ($story === null || $story->getDeletedAt() !== null) {
                $io->text(sprintf('  ✗ %s — la publicación no existe o está borrada', $id));
                ++$fails;
                continue;
            }
            if (!$story->isImage()) {
                $io->text(sprintf('  · «%s» — es un vídeo, no una foto: no se toca', $story->getTitle()));
                continue;
            }

            $old = $story->getUrl();
            try {
                $contents = $this->http->request('GET', $old, ['timeout' => 30])->getContent();
                $framed   = $this->frame->fitIfWide($contents);
            } catch (\Throwable $e) {
                $io->text(sprintf('  ✗ «%s» — no se pudo leer la foto: %s', $story->getTitle(), $e->getMessage()));
                ++$fails;
                continue;
            }

            if ($framed === null) {
                $io->text(sprintf('  · «%s» — ya es vertical: se queda como está', $story->getTitle()));
                if ($apply) {
                    $this->markChecked($story->getId());
                }
                continue;
            }

            if (!$apply) {
                $io->text(sprintf('  ✓ «%s» — se enmarcaría (%s)', $story->getTitle(), $old));
                continue;
            }

            $url = $this->storage->upload(
                fn (string $ext): string => sprintf('geostories/%s/%d.%s', $story->getId(), time(), $ext),
                $framed,
            );
            $story->setUrl($url)->setThumbnail($url);
            $this->geoStories->save($story);
            // Después de guardar: si fallara el guardado, la publicación se
            // quedaría apuntando a un fichero borrado.
            if ($old !== $url) {
                $this->storage->deleteByUrl($old);
            }
            $this->markChecked($story->getId());

            $io->text(sprintf('  ✓ «%s» — enmarcada: %s', $story->getTitle(), $url));
        }

        if (!$apply) {
            $io->note('En seco: no se ha cambiado nada. Añade --apply para hacerlo.');
        }

        return $fails > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Las fotos subidas desde la app (sin `external_ref`, que es lo del
     * scraping) que nadie ha revisado aún.
     *
     * @return list<string>
     */
    private function pending(): array
    {
        return $this->db->fetchFirstColumn(
            "SELECT id FROM geostories
              WHERE media_type = 'image'
                AND external_ref IS NULL
                AND deleted_at IS NULL
                AND COALESCE(meta->>'frame_checked_at', '') = ''
              ORDER BY created_at DESC",
        );
    }

    private function markChecked(string $id): void
    {
        $this->db->executeStatement(
            "UPDATE geostories
                SET meta = jsonb_set(COALESCE(meta::jsonb, '{}'::jsonb), '{frame_checked_at}', to_jsonb(NOW()))::json
              WHERE id = ?",
            [$id],
        );
    }

    /**
     * El id de la publicación: tal cual, en la URL, o dentro de la página del
     * enlace de Branch. Esa página lleva también el id del autor, así que se
     * queda con el que de verdad es una publicación.
     */
    private function resolve(string $ref): ?string
    {
        $candidates = [];
        if (preg_match_all(self::UUID, $ref, $m)) {
            $candidates = $m[0];
        } elseif (preg_match('#^https?://#', $ref)) {
            try {
                $page = $this->http->request('GET', $ref, ['timeout' => 15])->getContent(false);
            } catch (\Throwable) {
                return null;
            }
            preg_match_all(self::UUID, $page, $m);
            $candidates = $m[0];
        }

        foreach (array_unique(array_map('strtolower', $candidates)) as $id) {
            if ($this->db->fetchOne('SELECT 1 FROM geostories WHERE id = ?', [$id]) !== false) {
                return $id;
            }
        }

        return null;
    }
}
