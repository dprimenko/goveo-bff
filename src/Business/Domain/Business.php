<?php

declare(strict_types=1);

namespace App\Business\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'business')]
class Business
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $avatar;

    #[ORM\Column(name: 'main_image', type: 'text', nullable: true)]
    private ?string $mainImage;

    #[ORM\Column(name: 'category_id', type: 'guid')]
    private string $categoryId;

    #[ORM\Column(name: 'creator_id', type: 'guid')]
    private string $creatorId;

    #[ORM\Column(name: 'partner_id', type: 'guid', nullable: true)]
    private ?string $partnerId;

    /**
     * PostGIS GEOMETRY(Point,4326) column.
     * Value format: EWKT string, 'SRID=4326;POINT(lng lat)'.
     */
    #[ORM\Column(
        type: 'geometry',
        nullable: true,
        options: ['geometry_type' => 'POINT', 'srid' => 4326],
    )]
    private mixed $location = null;

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

    /** Cuándo salió la bienvenida. Nulo = no le ha llegado a su dueño. */
    #[ORM\Column(name: 'welcome_email_sent_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $welcomeEmailSentAt = null;

    public function __construct(
        string $id,
        string $slug,
        string $categoryId,
        string $creatorId,
        ?string $name = null,
        ?string $description = null,
        ?string $avatar = null,
        ?string $mainImage = null,
        ?string $partnerId = null,
        ?array $meta = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->id = $id;
        $this->slug = $slug;
        $this->categoryId = $categoryId;
        $this->creatorId = $creatorId;
        $this->name = $name;
        $this->description = $description;
        $this->avatar = $avatar;
        $this->mainImage = $mainImage;
        $this->partnerId = $partnerId;
        $this->meta = $meta;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
        $this->deletedAt = null;
        $this->verifiedAt = null;
    }

    public function getId(): string { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function getName(): ?string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getAvatar(): ?string { return $this->avatar; }
    public function getMainImage(): ?string { return $this->mainImage; }
    public function getCategoryId(): string { return $this->categoryId; }
    public function getCreatorId(): string { return $this->creatorId; }
    public function getPartnerId(): ?string { return $this->partnerId; }
    public function getMeta(): ?array { return $this->meta; }
    public function getLocation(): mixed { return $this->location; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }

    public function setName(?string $name): self
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setAvatar(?string $avatar): self
    {
        $this->avatar = $avatar;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Set location from latitude/longitude. Stored as an EWKT string — the
     * PostGIS geometry type binds via ST_GeomFromEWKT(?), so a GeoJSON array
     * would fail on flush with "Array to string conversion". Mismo criterio que
     * `GeoStory::setLocation`.
     */
    /**
     * Coordenadas a partir del EWKT guardado (`SRID=4326;POINT(lng lat)`).
     * Se leen del texto porque es como PostGIS devuelve la columna por el ORM;
     * las consultas geográficas usan `ST_X`/`ST_Y` directamente en SQL.
     */
    public function getLongitude(): ?float
    {
        return $this->coordinate(0);
    }

    public function getLatitude(): ?float
    {
        return $this->coordinate(1);
    }

    private function coordinate(int $index): ?float
    {
        if (!is_string($this->location)) {
            return null;
        }
        if (preg_match('/POINT\s*\(([-\d.]+)\s+([-\d.]+)\)/i', $this->location, $m) !== 1) {
            return null;
        }

        return (float) $m[$index + 1];
    }

    public function setMainImage(?string $mainImage): self
    {
        $this->mainImage = $mainImage;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Cambiar de categoría mueve el negocio de sitio en el mapa y en las
     * búsquedas, así que **retira la validación**: vuelve a la cola para que
     * alguien confirme que encaja donde dice. Devuelve si eso ha ocurrido, para
     * poder avisar a quien edita.
     */
    public function changeCategory(string $categoryId): bool
    {
        if ($categoryId === $this->categoryId) {
            return false;
        }

        $this->categoryId = $categoryId;
        $this->updatedAt  = new \DateTimeImmutable();

        $wasVerified = $this->verifiedAt !== null;
        $this->verifiedAt = null;

        return $wasVerified;
    }

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

    public function setMeta(?array $meta): self
    {
        $this->meta = $meta;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getWelcomeEmailSentAt(): ?\DateTimeImmutable { return $this->welcomeEmailSentAt; }

    public function markWelcomeEmailSent(): self
    {
        $this->welcomeEmailSentAt = new \DateTimeImmutable();
        return $this;
    }

    public function verify(): self
    {
        $this->verifiedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /** Retira la validación: el negocio deja de salir en feed, mapa y búsqueda. */
    public function unverify(): self
    {
        $this->verifiedAt = null;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function softDelete(): self
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isDeleted(): bool { return $this->deletedAt !== null; }
    public function isVerified(): bool { return $this->verifiedAt !== null; }
}
