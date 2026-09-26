<?php

declare(strict_types=1);

namespace App\Categories\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'categories')]
#[ORM\Index(name: 'idx_categories_parent_id', columns: ['parent_id'])]
class Category
{
    // Which content owners can publish a video under this category.
    public const MODE_INFLUENCER = 'influencer';
    public const MODE_BUSINESS   = 'business';
    public const MODE_BOTH       = 'both';

    public const SECTION_LOCAL   = 'local';
    public const SECTION_TOURISM = 'tourism';

    /**
     * Al filtrar **negocios**, categoría de antes => la que ocupa su sitio.
     *
     * Las apps publicadas piden por los slugs e ids de siempre: `accommodation`
     * está en su lista de turismo y «Cultura» es uno de sus círculos. Sin esto,
     * el filtro caería sobre una categoría borrada o que ya no tiene negocios
     * —`culture` quedó sólo para vídeos de influencer; sus teatros están en
     * `tourism-culture`— y esos negocios cambiarían de pestaña.
     */
    public const BUSINESS_FILTER_ALIASES = [
        'food'             => 'gourmet',
        'eco'              => 'gourmet',
        'accommodation'    => 'tourism-accommodation',
        'culture-business' => 'tourism-culture',
        'culture'          => 'tourism-culture',
    ];

    /** Las de influencer que ya eran «turismo» antes de que hubiera grupos. */
    public const LEGACY_TOURISM_SLUGS = ['place', 'culture', 'nature', 'events'];

    /** Slug → mode overrides; everything else defaults to business. */
    private const MODE_BY_SLUG = [
        'historicalbusiness' => self::MODE_BOTH,
        'hostelry'           => self::MODE_BOTH,
        'place'              => self::MODE_INFLUENCER,
        'events'             => self::MODE_INFLUENCER,
        'news'               => self::MODE_INFLUENCER,
        'nature'             => self::MODE_INFLUENCER,
        'culture'            => self::MODE_INFLUENCER,
    ];

    public static function modeForSlug(?string $slug): string
    {
        return self::MODE_BY_SLUG[$slug] ?? self::MODE_BUSINESS;
    }

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $name;

    #[ORM\Column(type: 'string', length: 100, nullable: true, unique: true)]
    private ?string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $image;

    #[ORM\Column(name: '`order`', type: 'integer', nullable: true)]
    private ?int $order;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $partner;

    /** influencer | business | both — who can post video under this category. */
    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::MODE_BUSINESS])]
    private string $mode;

    /**
     * La categoría de la que cuelga, si es una subcategoría (las de Eventos).
     * Nula en las de primer nivel, que son las que lista `/public/categories`
     * si no se pide otra cosa.
     */
    #[ORM\Column(name: 'parent_id', type: 'guid', nullable: true)]
    private ?string $parentId = null;

    /**
     * `local` | `tourism` en los **grupos** (Gastronomía, Alojamientos…); nula
     * en el resto. Es lo que reparte la home y los feeds entre «Comercio
     * local» y «Turismo».
     */
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $section = null;

    /**
     * Si se enseña al público. Las subcategorías de los grupos nacen apagadas
     * y se encienden grupo a grupo cuando tienen volumen, para que no salga
     * ningún chip vacío. Apagada sigue siendo asignable: sólo deja de listarse.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt;

    public function __construct(
        string $id,
        ?string $name = null,
        ?string $slug = null,
        ?string $image = null,
        ?int $order = null,
        ?string $partner = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
        string $mode = self::MODE_BUSINESS,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->slug = $slug;
        $this->image = $image;
        $this->order = $order;
        $this->partner = $partner;
        $this->mode = $mode;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
        $this->deletedAt = null;
    }

    public function getId(): string { return $this->id; }
    public function getName(): ?string { return $this->name; }
    public function getSlug(): ?string { return $this->slug; }
    public function getImage(): ?string { return $this->image; }
    public function getOrder(): ?int { return $this->order; }
    public function getPartner(): ?string { return $this->partner; }
    public function getMode(): string { return $this->mode; }
    public function setMode(string $mode): self { $this->mode = $mode; $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }

    public function setName(?string $name): self
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setSlug(?string $slug): self
    {
        $this->slug = $slug;
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

    public function getParentId(): ?string { return $this->parentId; }
    public function getSection(): ?string { return $this->section; }
    public function isGroup(): bool { return $this->section !== null; }
    public function isActive(): bool { return $this->active; }

    public function setActive(bool $active): self
    {
        $this->active = $active;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setOrder(?int $order): self
    {
        $this->order = $order;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
