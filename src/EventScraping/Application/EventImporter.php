<?php

declare(strict_types=1);

namespace App\EventScraping\Application;

use App\Categories\Application\Subcategories;
use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Infrastructure\PosterFrame;
use App\EventScraping\Infrastructure\WebPage;
use App\GeoStories\Domain\GeoStory;
use App\GeoStories\Domain\GeoStoryRepository;
use App\Shared\Infrastructure\Storage\BunnyStorageService;
use App\Shared\Infrastructure\Storage\StorageException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Convierte lo que publica una fuente en eventos de Goveo **sin validar**.
 *
 * Cada evento nace como una geostory de **foto** en la categoría `events`, con
 * sus fechas, el enlace a la web del evento y `external_ref` para no volver a
 * crearlo. No se valida ni se anuncia por correo: entra en la pestaña «Sin
 * validar (Scraping)» del panel, y de ahí sale quien lo apruebe.
 *
 * **Sin imagen no entra.** Una tarjeta vacía en el feed no la abre nadie, y el
 * cartel es lo que decide si un plan apetece. Y entra **en vertical** (9:16, con
 * bandas negras; ver `PosterFrame`), que es como la pinta la app.
 */
final class EventImporter
{
    public const SKIP_WINDOW    = 'fuera de fechas';
    public const SKIP_EXISTING  = 'ya importado';
    public const SKIP_NO_IMAGE  = 'sin imagen';
    public const SKIP_BAD_IMAGE = 'imagen no válida';
    public const SKIP_BAD_LINK  = 'sin enlace válido';

    private ?string $eventsCategoryId = null;

    public function __construct(
        private readonly Connection $db,
        private readonly WebPage $web,
        private readonly BunnyStorageService $storage,
        private readonly GeoStoryRepository $geoStories,
        private readonly VenueRegistry $venues,
        private readonly AgendaPublisher $agenda,
        private readonly PosterFrame $frame,
        private readonly EntityManagerInterface $em,
        private readonly Subcategories $subcategories,
    ) {}

    /**
     * @param callable(string $outcome, ScrapedEvent $event, ?string $detail): void $report
     *        `outcome` es `created`, `would-create` o uno de los `SKIP_*`.
     */
    public function run(EventSource $source, EventWindow $window, bool $dryRun, int $limit, callable $report): void
    {
        $existing = $this->existingRefs($source->name());
        $runAt    = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid'));
        $created  = 0;

        $candidates = [];
        foreach ($source->fetch() as $event) {
            if (!$window->accepts($event)) {
                $report(self::SKIP_WINDOW, $event, null);
            } elseif (isset($existing[$source->name() . ':' . $event->externalId])) {
                $report(self::SKIP_EXISTING, $event, null);
            } else {
                $candidates[] = $event;
            }
        }

        // Lo más próximo primero: con el tope de `limit`, lo que se queda fuera
        // tiene que ser lo que aún puede esperar a la siguiente pasada, no lo
        // que viene detrás en el orden de la fuente (el Ayuntamiento lo da por
        // orden alfabético).
        usort($candidates, fn (ScrapedEvent $a, ScrapedEvent $b) => $a->start <=> $b->start);

        foreach ($candidates as $event) {
            if ($created >= $limit) {
                break;
            }

            $event = $source->enrich($event);

            if ($event->imageUrl === null) {
                $report(self::SKIP_NO_IMAGE, $event, null);
                continue;
            }
            if (!$this->isWebUrl($event->link)) {
                $report(self::SKIP_BAD_LINK, $event, $event->link);
                continue;
            }

            // La sala: la que ya hay en Goveo, o una nueva sin validar. En seco
            // no se crea nada, sólo se dice qué pasaría.
            $venue = $this->venues->ownerFor($source, $event, $runAt, $dryRun);
            $owner = $venue['id'];
            if ($venue['created']) {
                $report('venue-created', $event, $venue['label']);
            }

            if ($dryRun) {
                $report('would-create', $event, $venue['label']);
                ++$created;
                continue;
            }

            $storyId = Uuid::v4()->toRfc4122();
            // Se descarga con margen: el límite de 8 MB es para lo que se sube, y
            // lo que se sube es el cartel ya reducido, no el original.
            $image   = $this->web->get($event->imageUrl, 4 * BunnyStorageService::MAX_BYTES);

            try {
                if ($image === null) {
                    throw new StorageException('no se pudo descargar');
                }
                $url = $this->storage->upload(
                    fn (string $ext): string => sprintf('geostories/%s/%d.%s', $storyId, time(), $ext),
                    $this->frame->fit($image),
                );
            } catch (StorageException|\RuntimeException $e) {
                $report(self::SKIP_BAD_IMAGE, $event, $e->getMessage());
                continue;
            } finally {
                // El original puede pesar decenas de megas: fuera antes de ir a
                // por el siguiente, no cuando acabe la vuelta.
                unset($image);
            }

            $story = new GeoStory(
                id: $storyId,
                thumbnail: $url,
                url: $url,
                title: mb_substr($event->title, 0, 255),
                description: $event->description,
                categoryId: $this->eventsCategoryId(),
                influencerId: $owner === null ? $this->agenda->forCity($event->city) : null,
                businessId: $owner,
                status: GeoStory::STATUS_READY,
                mediaType: GeoStory::MEDIA_IMAGE,
            );
            if ($event->latitude !== null && $event->longitude !== null) {
                $story->setLocation($event->latitude, $event->longitude);
            }
            // El tipo que diga la fuente (tablao → Flamenco…), o «Otros» si no
            // lo sabe; y su subnivel, si lo hay. Se corrige en el panel.
            $story->setSubcategoryId($this->subcategories->resolve($this->eventsCategoryId(), $event->subcategory));
            $story->setSubtypeId($this->subcategories->resolveSubtype($story->getSubcategoryId(), $event->subtype));
            $story->scheduleEvent($event->start, $event->end);
            $story->linkTo($event->link, $event->linkAction);
            $story->importedFrom($source->name(), $event->externalId, $runAt);

            $this->geoStories->save($story);
            $existing[$story->getExternalRef()] = true;
            ++$created;

            $report('created', $event, $story->getId());

            // Doctrine se queda con cada entidad guardada mientras dure el
            // proceso, y una pasada son cientos: se suelta tras cada una para
            // que la memoria no crezca con la vuelta. Nada de lo que sigue usa
            // entidades ya cargadas —la Agenda se recuerda por su id—.
            $this->em->clear();
            unset($story);
            gc_collect_cycles();
        }
    }

    /** @return array<string, true> */
    private function existingRefs(string $source): array
    {
        $refs = $this->db->fetchFirstColumn(
            'SELECT external_ref FROM geostories WHERE external_ref LIKE ?',
            [addcslashes($source, '%_') . ':%'],
        );

        return array_fill_keys($refs, true);
    }

    private function eventsCategoryId(): string
    {
        return $this->eventsCategoryId ??= (string) ($this->db->fetchOne(
            "SELECT id FROM categories WHERE slug = 'events' AND deleted_at IS NULL LIMIT 1",
        ) ?: throw new \RuntimeException('No existe la categoría «events»'));
    }

    /** Sólo `http`/`https`: el enlace acaba en un botón que la app abre a ciegas. */
    private function isWebUrl(?string $url): bool
    {
        return $url !== null
            && mb_strlen($url) <= 2048
            && filter_var($url, \FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, \PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
