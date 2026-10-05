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
    public const EXTENDED       = 'alargado';
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
                // Ya importado: no se vuelve a crear, pero si la fuente dice que
                // sigue más allá de lo guardado —un espectáculo que prorroga, uno
                // diario sin fin anunciado—, se le alarga el fin. Si no, dejaba
                // de verse al caducar aunque siguiera en cartel.
                $stored   = $existing[$source->name() . ':' . $event->externalId];
                $extended = $this->extend($stored, $event, $dryRun);
                // Lo importado antes de guardar la dirección se queda sin ella
                // —y sin «Cómo llegar»—: se completa al pasar otra vez.
                if (!$dryRun) {
                    $this->fillAddress($stored['id'], $event);
                }
                $report($extended ? self::EXTENDED : self::SKIP_EXISTING, $event, null);
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
            $story->locatedAt($this->address($event));
            // El tipo que diga la fuente (tablao → Flamenco…), o «Otros» si no
            // lo sabe; y su subnivel, si lo hay. Se corrige en el panel.
            $story->setSubcategoryId($this->subcategories->resolve($this->eventsCategoryId(), $event->subcategory));
            // Un concierto sin subnivel: clásica o moderna por el texto (ver
            // `ConcertKind`), para no repetir la regla en cada fuente.
            $subtype = $event->subtype;
            if ($subtype === null && $event->subcategory === 'events-small-concerts') {
                $subtype = ConcertKind::of(implode(' ', [$event->title, $event->venueName, $event->description ?? '']));
            }
            // Y el teatro, a Grandes teatros, Salas o centros culturales según
            // dónde sea (ver `TheaterKind`).
            if ($event->subcategory === TheaterKind::CULTURAL) {
                [$type, $subtype] = TheaterKind::of($source->name(), $event->venueName, $subtype);
                $story->setSubcategoryId($this->subcategories->resolve($this->eventsCategoryId(), $type));
            }
            $story->setSubtypeId($this->subcategories->resolveSubtype($story->getSubcategoryId(), $subtype));
            $story->scheduleEvent($event->start, $event->end);
            $story->linkTo($event->link, $event->linkAction);
            $story->importedFrom($source->name(), $event->externalId, $runAt);

            $this->geoStories->save($story);
            $existing[$story->getExternalRef()] = ['id' => $story->getId(), 'ended_at' => $story->getEndedAt()?->format(\DATE_ATOM)];
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

    /** @return array<string, array{id: string, ended_at: ?string}> */
    private function existingRefs(string $source): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT external_ref, id, ended_at FROM geostories WHERE external_ref LIKE ?',
            [addcslashes($source, '%_') . ':%'],
        );

        $refs = [];
        foreach ($rows as $row) {
            $refs[$row['external_ref']] = ['id' => $row['id'], 'ended_at' => $row['ended_at']];
        }

        return $refs;
    }

    /**
     * Alarga el fin de un evento ya importado si la fuente lo da más tarde.
     * **Sólo alarga**, nunca acorta —si la web recorta el rango, que se vea en el
     * panel—, y no toca lo borrado: lo descartado sigue descartado.
     *
     * @param array{id: string, ended_at: ?string} $stored
     */
    private function extend(array $stored, ScrapedEvent $event, bool $dryRun): bool
    {
        $end = $event->end ?? $event->start->add(new \DateInterval(GeoStory::EVENT_DEFAULT_DURATION));
        if ($stored['ended_at'] === null || $end <= new \DateTimeImmutable($stored['ended_at'])) {
            return false;
        }
        // En seco sólo se dice: una prueba no escribe nada.
        if ($dryRun) {
            return true;
        }

        return $this->db->executeStatement(
            'UPDATE geostories SET ended_at = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL',
            [$end->format(\DATE_ATOM), $stored['id']],
        ) > 0;
    }

    /**
     * La dirección del evento: la de la sala si la fuente la da y, si no, su
     * nombre y la ciudad, que es lo que se escribiría en el buscador del mapa.
     */
    private function address(ScrapedEvent $event): ?string
    {
        return $event->venueAddress ?? (trim($event->venueName . ', ' . $event->city, ' ,') ?: null);
    }

    /** Pone la dirección a un evento ya importado que no la tenga. */
    private function fillAddress(string $id, ScrapedEvent $event): void
    {
        if (($address = $this->address($event)) === null) {
            return;
        }

        $this->db->executeStatement(
            "UPDATE geostories
                SET meta = jsonb_set(COALESCE(meta::jsonb, '{}'::jsonb), '{address}', to_jsonb(?::text))::json
              WHERE id = ? AND deleted_at IS NULL AND (meta->>'address') IS NULL",
            [mb_substr($address, 0, 255), $id],
        );
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
