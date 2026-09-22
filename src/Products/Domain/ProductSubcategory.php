<?php

declare(strict_types=1);

namespace App\Products\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Subcategories created by businesses to organise their own product catalogue.
 * These are distinct from system-level categories/default_subcategories.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_subcategories')]
class ProductSubcategory
{
    /** Una subcategoría cualquiera, creada por quien gestiona el negocio. */
    public const KIND_CUSTOM = 'custom';

    /**
     * La de promociones, que existe en todos los negocios y no la crea nadie.
     *
     * Se marca con una **columna** y no se reconoce por su nombre: el nombre lo
     * puede cambiar quien gestiona la tienda y además es una clave de
     * traducción, así que buscar «Promos» dejaría de encontrarla el día que
     * alguien la renombre o la app salga en otro idioma.
     */
    public const KIND_PROMOS = 'promos';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'business_id', type: 'guid')]
    private string $businessId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $description;

    #[ORM\Column(name: 'sort_order', type: 'integer', options: ['default' => 0])]
    private int $sortOrder;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::KIND_CUSTOM])]
    private string $kind;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        string $businessId,
        string $name,
        ?string $description = null,
        int $sortOrder = 0,
        ?\DateTimeImmutable $createdAt = null,
        string $kind = self::KIND_CUSTOM,
    ) {
        $this->id          = $id;
        $this->businessId  = $businessId;
        $this->name        = $name;
        $this->description = $description;
        $this->sortOrder   = $sortOrder;
        $this->createdAt   = $createdAt ?? new \DateTimeImmutable();
        $this->kind        = $kind === self::KIND_PROMOS ? self::KIND_PROMOS : self::KIND_CUSTOM;
    }

    /**
     * La de promociones de un negocio, tal y como nace.
     *
     * Con `sortOrder` negativo para que salga la primera sin competir con el
     * orden que haya puesto el gestor a las suyas: las propias empiezan en 0 y
     * suben, así que ninguna se le pone delante por accidente.
     */
    public static function promos(string $id, string $businessId, string $name): self
    {
        return new self(
            id:         $id,
            businessId: $businessId,
            name:       $name,
            sortOrder:  -1,
            kind:       self::KIND_PROMOS,
        );
    }

    public function getId(): string                    { return $this->id; }
    public function getBusinessId(): string            { return $this->businessId; }
    public function getName(): string                  { return $this->name; }
    public function getDescription(): ?string          { return $this->description; }
    public function getSortOrder(): int                { return $this->sortOrder; }
    public function getKind(): string                  { return $this->kind; }
    public function isPromos(): bool                   { return $this->kind === self::KIND_PROMOS; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /**
     * Cambia el nombre visible.
     *
     * Renombrar una adoptada de las por defecto la convierte en propia: deja de
     * ser una clave de traducción y pasa a ser texto literal. Es lo que quiere
     * quien la renombra —«Entrantes» → «Para picar»— y el nombre por defecto
     * sigue disponible para el resto de negocios.
     */
    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function reorder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }
}
