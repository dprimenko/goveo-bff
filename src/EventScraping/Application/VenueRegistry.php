<?php

declare(strict_types=1);

namespace App\EventScraping\Application;

use App\Business\Application\BusinessSlugger;
use App\Business\Domain\Business;
use App\Business\Domain\BusinessRepository;
use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\WebPage;
use App\EventScraping\Infrastructure\WebsiteProfile;
use App\Shared\Infrastructure\Storage\BunnyStorageService;
use App\Shared\Infrastructure\Storage\StorageException;
use App\Users\Domain\User;
use App\Users\Domain\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * El negocio de la sala de un evento: el que ya hay en Goveo, o uno nuevo **sin
 * validar** creado aquí con lo que se sepa de ella.
 *
 * Por orden:
 *
 * 1. **El que ya existe en su ciudad** (`VenueOwnerResolver`). Nunca se crea
 *    un duplicado de una sala que ya está dada de alta.
 * 2. **El que creó una pasada anterior**, por `external_ref` — aunque se haya
 *    cambiado el nombre en el panel. Si se descartó, no se vuelve a crear: sus
 *    eventos van a la Agenda.
 * 3. **Uno nuevo**, si la fuente sabe de la sala (`EventSource::venueFor`).
 *
 * El negocio nuevo lleva `external_ref` y `meta.origin` como los eventos, y sale
 * en la pestaña «Sin validar (Scraping)» de Negocios. **Sus eventos no se ven
 * hasta validarlo** (ver `findFeed`): llevarían a una ficha que no es pública.
 */
final class VenueRegistry
{
    /** Espacio de nombres del usuario «Goveo Scraping», dueño de lo creado aquí. */
    private const SYSTEM_USER_NS = '3f6f4e2a-8f1b-5c0e-9a52-7d1c2b6e9f10';

    /** @var array<string, array{id: ?string, label: string}> */
    private array $cache = [];

    private ?string $systemUserId = null;

    public function __construct(
        private readonly VenueOwnerResolver $resolver,
        private readonly BusinessRepository $businesses,
        private readonly BusinessSlugger $slugger,
        private readonly UserRepository $users,
        private readonly Connection $db,
        private readonly WebsiteProfile $profiles,
        private readonly WebPage $web,
        private readonly BunnyStorageService $storage,
    ) {}

    /**
     * @return array{id: ?string, label: string, created: bool}
     *         `id` nulo = no hay negocio claro, el evento va a la Agenda.
     */
    public function ownerFor(EventSource $source, ScrapedEvent $event, \DateTimeImmutable $runAt, bool $dryRun): array
    {
        $venue = $source->venueFor($event);
        $key   = $venue !== null ? $venue->source . ':' . $venue->externalId : null;

        if ($key !== null && isset($this->cache[$key])) {
            return $this->cache[$key] + ['created' => false];
        }

        // 1. Ya en Goveo.
        $existing = $this->resolver->businessFor($event);
        if ($existing !== null) {
            return $this->remember($key, $existing, 'negocio ' . $existing) + ['created' => false];
        }
        if ($venue === null) {
            return ['id' => null, 'label' => 'Agenda Goveo · ' . $event->city, 'created' => false];
        }

        // 2. Creado por una pasada anterior (también si se descartó).
        $previous = $this->db->fetchAssociative(
            'SELECT id, deleted_at FROM business WHERE external_ref = ?',
            [$key],
        );
        if ($previous !== false) {
            return $previous['deleted_at'] !== null
                ? $this->remember($key, null, 'Agenda Goveo · ' . $event->city . ' (sala descartada)') + ['created' => false]
                : $this->remember($key, $previous['id'], 'negocio ' . $previous['id']) + ['created' => false];
        }

        // 3. Nuevo.
        if ($dryRun) {
            return $this->remember($key, null, 'negocio nuevo «' . $venue->name . '»') + ['created' => true];
        }

        $business = $this->create($venue, $event, $runAt);

        return $this->remember($key, $business->getId(), 'negocio nuevo «' . $venue->name . '»') + ['created' => true];
    }

    /** @return array{id: ?string, label: string} */
    private function remember(?string $key, ?string $id, string $label): array
    {
        $entry = ['id' => $id, 'label' => $label];
        if ($key !== null) {
            $this->cache[$key] = $entry;
        }

        return $entry;
    }

    private function create(ScrapedVenue $venue, ScrapedEvent $event, \DateTimeImmutable $runAt): Business
    {
        $profile = $venue->website !== null
            ? $this->profiles->of($venue->website)
            : ['avatar' => null, 'cover' => null, 'description' => null, 'phone' => null];

        $business = new Business(
            id: Uuid::v4()->toRfc4122(),
            slug: $this->slugger->forName($venue->name),
            categoryId: $this->categoryId($venue->categorySlug),
            creatorId: $this->systemUser(),
            name: $venue->name,
            description: $venue->description ?? $profile['description'],
            meta: array_filter([
                'address'      => $venue->address,
                'public_phone' => $venue->phone ?? $profile['phone'],
                'website_url'  => $venue->website,
            ]),
        );
        if ($venue->latitude !== null && $venue->longitude !== null) {
            $business->setLocation($venue->latitude, $venue->longitude);
        }
        $business->setCity($venue->city);
        $business->importedFrom($venue->source, $venue->externalId, $runAt);

        // Se guarda antes de subir las imágenes: la ruta del almacenamiento
        // lleva su id, y sin fila no habría a quién apuntar si la subida va bien.
        $this->businesses->save($business);

        // El escaparate: la imagen de la web y, si no tiene, el cartel del
        // evento que la ha traído, que al menos es de esa sala.
        $avatar = $this->upload($business->getId(), 'avatar', $profile['avatar']);
        $cover  = $this->upload($business->getId(), 'main_image', $profile['cover'] ?? $event->imageUrl);

        if ($avatar !== null || $cover !== null) {
            $business->setAvatar($avatar);
            $business->setMainImage($cover);
            $this->businesses->save($business);
        }

        return $business;
    }

    /** Sube una imagen de la sala; si no se puede, la ficha sale sin ella. */
    private function upload(string $businessId, string $slot, ?string $url): ?string
    {
        if ($url === null || ($contents = $this->web->get($url, BunnyStorageService::MAX_BYTES)) === null) {
            return null;
        }

        try {
            return $this->storage->uploadBusinessImage($businessId, $slot, $contents);
        } catch (StorageException) {
            return null;
        }
    }

    private function categoryId(string $slug): string
    {
        $id = $this->db->fetchOne('SELECT id FROM categories WHERE slug = ? AND deleted_at IS NULL LIMIT 1', [$slug]);

        return $id !== false
            ? (string) $id
            : throw new \RuntimeException(sprintf('No existe la categoría «%s»', $slug));
    }

    /**
     * «Goveo Scraping», el usuario que figura como creador de las salas. Sin
     * correo: nadie entra con él, sólo existe porque un negocio tiene creador.
     * Id determinista (UUID v5) para encontrarlo sin buscarlo por nombre.
     */
    private function systemUser(): string
    {
        if ($this->systemUserId !== null) {
            return $this->systemUserId;
        }

        $id = Uuid::v5(Uuid::fromString(self::SYSTEM_USER_NS), 'goveo-scraping')->toRfc4122();
        if ($this->users->findById($id) === null) {
            $this->users->save(new User(id: $id, email: null, name: 'Goveo Scraping'));
        }

        return $this->systemUserId = $id;
    }
}
