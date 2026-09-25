<?php

declare(strict_types=1);

namespace App\GeoStories\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * The `location` field uses PostGIS GEOMETRY(Point, 4326).
 * It is stored and retrieved as GeoJSON array:
 *   ['type' => 'Point', 'coordinates' => [$longitude, $latitude]]
 */
#[ORM\Entity]
#[ORM\Table(name: 'geostories')]
#[ORM\Index(name: 'idx_geostories_location_gist', columns: ['location'])]
#[ORM\Index(name: 'idx_geostories_visible', columns: ['started_at', 'created_at'], options: ['where' => '(deleted_at IS NULL) AND (verified_at IS NOT NULL)'])]
#[ORM\Index(name: 'idx_geostories_business_id', columns: ['business_id'], options: ['where' => 'deleted_at IS NULL'])]
#[ORM\Index(name: 'idx_geostories_category_id', columns: ['category_id'], options: ['where' => 'deleted_at IS NULL'])]
#[ORM\Index(name: 'idx_geostories_influencer_id', columns: ['influencer_id'], options: ['where' => 'deleted_at IS NULL'])]
#[ORM\Index(name: 'idx_geostories_subcategory_id', columns: ['subcategory_id'], options: ['where' => 'deleted_at IS NULL'])]
#[ORM\UniqueConstraint(name: 'uniq_geostories_external_ref', columns: ['external_ref'])]
class GeoStory
{
    /**
     * Qué se publicó: un vídeo o una foto.
     *
     * Una foto no se codifica ni tiene reproductor, así que no pasa por Bunny
     * Stream ni espera a ningún aviso: nace lista. Va en columna y no en `meta`
     * porque decide qué pinta la app en la tarjeta, y eso es del dominio.
     */
    public const MEDIA_VIDEO = 'video';
    public const MEDIA_IMAGE = 'image';

    /** A dónde puede llevar el botón de un vídeo o una foto. */
    public const LINK_ACTIONS = ['buy', 'book', 'info'];

    // Transcoding lifecycle (Bunny Stream).
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY       = 'ready';
    public const STATUS_FAILED      = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description;

    #[ORM\Column(type: 'text')]
    private string $thumbnail;

    #[ORM\Column(type: 'text')]
    private string $url;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $likes;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $views;

    /**
     * PostGIS GEOMETRY(Point,4326) column.
     * Value format: ['type' => 'Point', 'coordinates' => [$lng, $lat]]
     */
    #[ORM\Column(
        type: 'geometry',
        nullable: true,
        options: ['geometry_type' => 'POINT', 'srid' => 4326],
    )]
    private mixed $location;

    #[ORM\Column(name: 'category_id', type: 'guid', nullable: true)]
    private ?string $categoryId;

    /**
     * La subcategoría de un evento («Escena», «Flamenco»…): una categoría hija
     * de `events`. Va aparte de `categoryId` porque lo que decide si algo es un
     * evento mira la categoría, y cambiarla lo sacaría de Eventos. Nula fuera
     * de Eventos.
     */
    #[ORM\Column(name: 'subcategory_id', type: 'guid', nullable: true)]
    private ?string $subcategoryId = null;

    /**
     * El subnivel del tipo de evento (Noche y fiesta → Discotecas): hijo de
     * `subcategoryId`. No se filtra por él —se guarda para que los datos estén
     * completos—, y es opcional: sin él, el evento se queda con su tipo.
     */
    #[ORM\Column(name: 'subtype_id', type: 'guid', nullable: true)]
    private ?string $subtypeId = null;

    #[ORM\Column(name: 'influencer_id', type: 'guid', nullable: true)]
    private ?string $influencerId;

    #[ORM\Column(name: 'business_id', type: 'guid', nullable: true)]
    private ?string $businessId;

    #[ORM\Column(name: 'is_main', type: 'boolean', options: ['default' => false])]
    private bool $isMain;

    /** Transcoding status: processing | ready | failed. */
    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_READY])]
    private string $status;

    /** Bunny Stream video GUID (provider-neutral name). */
    #[ORM\Column(name: 'media_type', type: 'string', length: 10, options: ['default' => self::MEDIA_VIDEO])]
    private string $mediaType;

    #[ORM\Column(name: 'provider_video_id', type: 'string', length: 255, nullable: true)]
    private ?string $providerVideoId;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $meta;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt;

    #[ORM\Column(name: 'verified_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt;

    /**
     * De dónde se importó, como `fuente:id` (`berlin:sr-chinarro`). Nulo en lo
     * que sube la gente.
     *
     * Es lo que impide que el scraping cree dos veces el mismo evento, y por eso
     * el índice único **no** excluye lo borrado: un evento que alguien descartó
     * tiene que seguir descartado en la siguiente pasada, no volver a la cola.
     */
    #[ORM\Column(name: 'external_ref', type: 'string', length: 255, nullable: true)]
    private ?string $externalRef = null;

    #[ORM\Column(name: 'started_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'ended_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt;

    public function __construct(
        string $id,
        string $thumbnail,
        string $url,
        ?string $title = null,
        ?string $description = null,
        ?string $categoryId = null,
        ?string $influencerId = null,
        ?string $businessId = null,
        mixed $location = null,
        bool $isMain = false,
        ?array $meta = null,
        string $status = self::STATUS_READY,
        ?string $providerVideoId = null,
        string $mediaType = self::MEDIA_VIDEO,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->id = $id;
        $this->thumbnail = $thumbnail;
        $this->url = $url;
        $this->title = $title;
        $this->description = $description;
        $this->categoryId = $categoryId;
        $this->influencerId = $influencerId;
        $this->businessId = $businessId;
        $this->location = $location;
        $this->isMain = $isMain;
        $this->meta = $meta;
        $this->status = $status;
        $this->providerVideoId = $providerVideoId;
        $this->mediaType = $mediaType === self::MEDIA_IMAGE ? self::MEDIA_IMAGE : self::MEDIA_VIDEO;
        $this->likes = 0;
        $this->views = 0;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
        $this->deletedAt = null;
        $this->verifiedAt = null;
        $this->startedAt = null;
        $this->endedAt = null;
    }

    public function getId(): string { return $this->id; }
    public function getTitle(): ?string { return $this->title; }
    public function getDescription(): ?string { return $this->description; }
    public function getThumbnail(): string { return $this->thumbnail; }
    public function getUrl(): string { return $this->url; }
    public function getLikes(): int { return $this->likes; }
    public function getViews(): int { return $this->views; }
    public function getLocation(): mixed { return $this->location; }
    public function getCategoryId(): ?string { return $this->categoryId; }
    public function getInfluencerId(): ?string { return $this->influencerId; }
    public function getSubcategoryId(): ?string { return $this->subcategoryId; }

    public function setSubcategoryId(?string $subcategoryId): self
    {
        $this->subcategoryId = $subcategoryId;
        $this->updatedAt     = new \DateTimeImmutable();

        return $this;
    }

    public function getSubtypeId(): ?string { return $this->subtypeId; }

    public function setSubtypeId(?string $subtypeId): self
    {
        $this->subtypeId = $subtypeId;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
    public function getBusinessId(): ?string { return $this->businessId; }
    public function isMain(): bool { return $this->isMain; }
    public function getStatus(): string { return $this->status; }
    public function getProviderVideoId(): ?string { return $this->providerVideoId; }
    public function getMediaType(): string { return $this->mediaType; }
    public function isImage(): bool { return $this->mediaType === self::MEDIA_IMAGE; }
    public function getMeta(): ?array { return $this->meta; }

    /** URL externa a la que lleva el botón de la tarjeta, si la hay. */
    public function getLinkUrl(): ?string
    {
        $url = $this->meta['link_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** Qué se va a hacer allí: comprar, reservar o informarse. */
    public function getLinkAction(): ?string
    {
        if ($this->getLinkUrl() === null) {
            return null;
        }

        $action = $this->meta['link_action'] ?? null;

        // Se guarda la intención y no el rótulo, igual que en el producto: así
        // el botón sale en el idioma de quien mira.
        return in_array($action, self::LINK_ACTIONS, true) ? $action : 'info';
    }

    /**
     * Pone o quita el enlace externo.
     *
     * Quitar la URL se lleva por delante la acción: una acción sin destino no
     * pinta nada y quedaría ahí esperando a confundir al siguiente que mire.
     */
    public function linkTo(?string $url, ?string $action): self
    {
        $meta = $this->meta ?? [];
        unset($meta['link_url'], $meta['link_action']);

        if ($url !== null && $url !== '') {
            $meta['link_url']    = $url;
            $meta['link_action'] = in_array($action, self::LINK_ACTIONS, true) ? $action : 'info';
        }

        $this->meta      = $meta ?: null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function getEndedAt(): ?\DateTimeImmutable { return $this->endedAt; }
    public function getExternalRef(): ?string { return $this->externalRef; }

    /**
     * Marca la publicación como importada por el scraping de eventos.
     *
     * `origin` lleva el día de la pasada (`scraping_2026-09-23`): cuando algo
     * sale raro en la cola, lo primero que se pregunta es de qué ejecución vino.
     */
    public function importedFrom(string $source, string $externalId, \DateTimeImmutable $runAt): self
    {
        $this->externalRef = mb_substr($source . ':' . $externalId, 0, 255);

        $meta                  = $this->meta ?? [];
        $meta['origin']        = 'scraping_' . $runAt->format('Y-m-d');
        $meta['origin_source'] = $source;
        $this->meta            = $meta;
        $this->updatedAt       = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Pasa la publicación a otro dueño: un negocio **o** un influencer.
     *
     * Lo usa el panel cuando lo importado se colgó de «Agenda Goveo» y después
     * se dio de alta la sala, o cuando el scraping acertó el nombre pero no el
     * sitio.
     */
    public function reassignTo(?string $businessId, ?string $influencerId): self
    {
        if (($businessId === null) === ($influencerId === null)) {
            throw new \InvalidArgumentException('A geostory belongs to a business or to an influencer, never both.');
        }

        $this->businessId   = $businessId;
        $this->influencerId = $influencerId;
        $this->updatedAt    = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Set location from latitude/longitude. Stored as an EWKT string — the
     * PostGIS geometry type binds via ST_GeomFromEWKT(?), so a GeoJSON array
     * would fail on flush with "Array to string conversion".
     */
    public function setLocation(float $latitude, float $longitude): self
    {
        $this->location = sprintf('SRID=4326;POINT(%.7f %.7f)', $longitude, $latitude);
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function clearLocation(): self
    {
        $this->location = null;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setCategoryId(?string $categoryId): self
    {
        $this->categoryId = $categoryId;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function incrementViews(): self
    {
        ++$this->views;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function incrementLikes(): self
    {
        ++$this->likes;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setThumbnail(string $thumbnail): self
    {
        $this->thumbnail = $thumbnail;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setProviderVideoId(?string $providerVideoId): self
    {
        $this->providerVideoId = $providerVideoId;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markProcessing(): self
    {
        $this->status = self::STATUS_PROCESSING;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markReady(): self
    {
        $this->status = self::STATUS_READY;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markFailed(): self
    {
        $this->status = self::STATUS_FAILED;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isReady(): bool { return $this->status === self::STATUS_READY; }

    /**
     * Si ya se le dijo a su dueño que el vídeo no pasó la selección.
     *
     * Hace falta porque un vídeo rechazado **se queda donde estaba** —«sin
     * validar» es justo el estado del que venía—, así que la cola lo sigue
     * enseñando y el botón de rechazar se puede volver a pulsar. El `PUT` es
     * idempotente a propósito, pero el correo no: mandarle dos veces el mismo
     * «necesita un ajuste» es decirle que ha fallado dos veces.
     *
     * Va en `meta` y no en una columna propia porque no es estado del vídeo,
     * es rastro de un envío; y aprobar lo borra, para que un rechazo posterior
     * —quien revisa se desdice— vuelva a avisar.
     */
    public function rejectionNoticeSent(): bool
    {
        return ($this->meta['rejection_notified_at'] ?? null) !== null;
    }

    public function markRejectionNoticeSent(): self
    {
        $meta                          = $this->meta ?? [];
        $meta['rejection_notified_at'] = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $this->meta                    = $meta;
        $this->updatedAt               = new \DateTimeImmutable();

        return $this;
    }

    public function clearRejectionNotice(): self
    {
        if (!$this->rejectionNoticeSent()) {
            return $this;
        }

        $meta = $this->meta ?? [];
        unset($meta['rejection_notified_at']);
        $this->meta      = $meta;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Cuánto dura un evento al que no se le pone hora de fin.
     *
     * Se pide la de inicio y se deja opcional la de fin porque quien publica
     * sabe cuándo empieza su concierto y muchas veces no cuándo acaba. Pero el
     * feed necesita las dos —un evento tiene que desaparecer cuando termina— y
     * sin un fin se quedaría a la vista para siempre.
     */
    public const EVENT_DEFAULT_DURATION = 'PT3H';

    /** Lo que vive una noticia antes de caer del feed por su propia edad. */
    public const NEWS_LIFESPAN = 'P7D';

    /**
     * Un evento: cuándo empieza y hasta cuándo se enseña.
     *
     * Sin fin, tres horas desde el inicio (`EVENT_DEFAULT_DURATION`). Se calcula
     * al guardar y no al leer para que la fecha que decide si el evento sigue
     * vivo esté escrita y se pueda mirar en la base — derivarla en cada consulta
     * obligaría a repetir la misma cuenta en el feed, el detalle y la app.
     */
    public function scheduleEvent(\DateTimeImmutable $start, ?\DateTimeImmutable $end = null): self
    {
        $this->startedAt = $start;
        $this->endedAt   = $end ?? $start->add(new \DateInterval(self::EVENT_DEFAULT_DURATION));
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Una noticia: vale desde ahora y caduca en una semana.
     *
     * No se pregunta nada porque no hay nada que preguntar: una noticia es de
     * hoy por definición. Y caduca sola para que el feed no se llene de cosas
     * de hace meses, que es lo que pasaba cuando la vigencia se sacaba de
     * `created_at` a ojo en la consulta.
     */
    public function scheduleNews(?\DateTimeImmutable $now = null): self
    {
        $start           = $now ?? new \DateTimeImmutable();
        $this->startedAt = $start;
        $this->endedAt   = $start->add(new \DateInterval(self::NEWS_LIFESPAN));
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /** Quita las fechas: lo que no es evento ni noticia no tiene vigencia. */
    public function clearSchedule(): self
    {
        $this->startedAt = null;
        $this->endedAt   = null;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }


    public function verify(): self
    {
        $this->verifiedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Lo saca de los feeds sin borrarlo: sigue estando, y su dueño lo sigue
     * viendo en su perfil, pero deja de ser público. Es lo que se quiere para
     * retirar un vídeo que no encaja sin destruir lo que alguien subió, y lo que
     * permite deshacer una decisión equivocada.
     */
    public function unverify(): self
    {
        $this->verifiedAt = null;
        $this->updatedAt  = new \DateTimeImmutable();
        return $this;
    }

    public function softDelete(): self
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Deshace el borrado. El vídeo vuelve donde estaba —validado o no, según su
     * `verifiedAt`—, que es lo que permite usar el borrado para quitar ruido de
     * la cola sin que sea una decisión definitiva.
     */
    public function restore(): self
    {
        $this->deletedAt = null;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isDeleted(): bool { return $this->deletedAt !== null; }
    public function isVerified(): bool { return $this->verifiedAt !== null; }
}
