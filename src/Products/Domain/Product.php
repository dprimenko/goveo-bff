<?php

declare(strict_types=1);

namespace App\Products\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'products')]
#[ORM\UniqueConstraint(name: 'uq_products_business_slug', columns: ['business_id', 'slug'])]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'business_id', type: 'guid')]
    private string $businessId;

    /**
     * Optional: product category, can differ from the store's category.
     * Allows a single store to span multiple discovery categories.
     */
    #[ORM\Column(name: 'category_id', type: 'guid', nullable: true)]
    private ?string $categoryId;

    #[ORM\Column(name: 'subcategory_id', type: 'guid', nullable: true)]
    private ?string $subcategoryId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'string', length: 255)]
    private string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description;

    /**
     * Format of the description field so clients can render correctly.
     */
    #[ORM\Column(name: 'description_format', type: 'string', length: 10, enumType: ContentFormat::class, options: ['default' => 'markdown'])]
    private ContentFormat $descriptionFormat;

    /**
     * Array of image objects: [{"url": "https://...", "order": 1}, ...]
     * Using jsonb for flexibility to add alt/width/height later without schema change.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $images;

    /**
     * Price in minor currency units (cents, pence, etc.).
     * E.g. 1999 = 19.99 EUR. Avoids float precision issues.
     * Null means price on request or free.
     */
    #[ORM\Column(name: 'price_amount', type: 'integer', nullable: true)]
    private ?int $priceAmount;

    /**
     * ISO 4217 currency code (EUR, USD, GBP, ...).
     */
    #[ORM\Column(name: 'price_currency', type: 'string', length: 3, nullable: true)]
    private ?string $priceCurrency;

    /**
     * Lo que acompaña al producto sin ser del dominio: hoy, el enlace directo
     * (`link_url` + `link_action`) a comprar o reservar en la web del negocio.
     *
     * Va aquí y no en columnas propias porque no es nuestro —no cobramos, no
     * reservamos y no sabemos qué hay al otro lado—, y porque lo de esta clase
     * llega de uno en uno: con columnas, cada añadido sería una migración.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $meta;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt;

    #[ORM\Column(name: 'published_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt;

    public function __construct(
        string $id,
        string $businessId,
        string $title,
        string $slug,
        ?string $categoryId = null,
        ?string $subcategoryId = null,
        ?string $description = null,
        ContentFormat $descriptionFormat = ContentFormat::Markdown,
        ?array $images = null,
        ?int $priceAmount = null,
        ?string $priceCurrency = null,
        ?array $meta = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->id                = $id;
        $this->businessId        = $businessId;
        $this->title             = $title;
        $this->slug              = $slug;
        $this->categoryId        = $categoryId;
        $this->subcategoryId     = $subcategoryId;
        $this->description       = $description;
        $this->descriptionFormat = $descriptionFormat;
        $this->images            = $images;
        $this->priceAmount       = $priceAmount;
        $this->priceCurrency     = $priceCurrency;
        $this->meta              = $meta;
        $this->createdAt         = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt         = $updatedAt ?? new \DateTimeImmutable();
        $this->deletedAt         = null;
        $this->publishedAt       = null;
    }

    public function getId(): string                       { return $this->id; }
    public function getBusinessId(): string               { return $this->businessId; }
    public function getCategoryId(): ?string              { return $this->categoryId; }
    public function getSubcategoryId(): ?string           { return $this->subcategoryId; }
    public function getTitle(): string                    { return $this->title; }
    public function getSlug(): string                     { return $this->slug; }
    public function getDescription(): ?string             { return $this->description; }
    public function getDescriptionFormat(): ContentFormat { return $this->descriptionFormat; }
    public function getImages(): ?array                   { return $this->images; }
    public function getPriceAmount(): ?int                { return $this->priceAmount; }
    public function getPriceCurrency(): ?string           { return $this->priceCurrency; }
    public function getMeta(): ?array                     { return $this->meta; }
    public function getCreatedAt(): \DateTimeImmutable    { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable    { return $this->updatedAt; }
    public function getDeletedAt(): ?\DateTimeImmutable   { return $this->deletedAt; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }

    /** Returns the price as a decimal float for display (e.g. 19.99). */
    public function getPriceDecimal(): ?float
    {
        if ($this->priceAmount === null) {
            return null;
        }

        return $this->priceAmount / 100;
    }

    /** Returns a formatted price string, e.g. "19.99 EUR". */
    public function getFormattedPrice(): ?string
    {
        if ($this->priceAmount === null || $this->priceCurrency === null) {
            return null;
        }

        return sprintf('%.2f %s', $this->priceAmount / 100, $this->priceCurrency);
    }

    /** Máximo de imágenes por producto: es lo que muestra la ficha. */
    public const MAX_IMAGES = 4;

    public function rename(string $title, string $slug): void
    {
        $this->title     = $title;
        $this->slug      = $slug;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function describe(?string $description, ContentFormat $format): void
    {
        $this->description       = $description;
        $this->descriptionFormat = $format;
        $this->updatedAt         = new \DateTimeImmutable();
    }

    /**
     * Las URLs de las imágenes, en su orden.
     *
     * El array guardado lleva `{url, order}` porque el orden vive en el dato y
     * no en la posición —así un borrado intermedio no reordena el resto—, pero
     * fuera sólo interesa la lista ya ordenada. La primera es la portada.
     *
     * @return string[]
     */
    public function imageUrls(): array
    {
        $images = $this->images ?? [];
        usort($images, static fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        return array_values(array_filter(array_map(
            static fn ($i) => $i['url'] ?? null,
            $images,
        )));
    }

    /** Mueve el producto a otra subcategoría del negocio, o lo saca de todas. */
    public function moveToSubcategory(?string $subcategoryId): void
    {
        $this->subcategoryId = $subcategoryId;
        $this->updatedAt     = new \DateTimeImmutable();
    }

    /**
     * Añade una imagen al final.
     *
     * El orden vive en el propio dato (`order`) y no en la posición del array
     * porque la ficha lo usa para decidir cuál es la principal, y un borrado
     * intermedio no debe reordenar el resto.
     *
     * @throws \DomainException si ya se alcanzó el máximo
     */
    public function addImage(string $url): void
    {
        $images = $this->images ?? [];

        if (count($images) >= self::MAX_IMAGES) {
            throw new \DomainException(sprintf(
                'Un producto admite %d imágenes como máximo.',
                self::MAX_IMAGES,
            ));
        }

        $images[]        = ['url' => $url, 'order' => count($images) + 1];
        $this->images    = $images;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Quita una imagen por su URL y renumera el resto. */
    public function removeImage(string $url): bool
    {
        $images = $this->images ?? [];
        $kept   = array_values(array_filter(
            $images,
            static fn (array $i) => ($i['url'] ?? null) !== $url,
        ));

        if (count($kept) === count($images)) {
            return false;
        }

        // Se renumera para que el orden siga siendo 1..n sin huecos: la ficha
        // asume que el primero es la portada.
        foreach ($kept as $i => $image) {
            $kept[$i]['order'] = $i + 1;
        }

        $this->images    = $kept ?: null;
        $this->updatedAt = new \DateTimeImmutable();

        return true;
    }

    public function publish(): void
    {
        $this->publishedAt = new \DateTimeImmutable();
        $this->updatedAt   = new \DateTimeImmutable();
    }

    public function softDelete(): void
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Acciones que puede ofrecer el enlace directo.
     *
     * Una lista cerrada y no el texto libre del botón: lo que se guarda es la
     * intención, y el texto lo pone la app en el idioma de quien mira. Con texto
     * libre, un botón escrito en español se le quedaría así a un inglés.
     */
    public const LINK_ACTIONS = ['buy', 'book', 'info'];

    /** URL externa a la que lleva el botón de la ficha, si la hay. */
    public function getLinkUrl(): ?string
    {
        $url = $this->meta['link_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** Qué se va a hacer allí: comprar, reservar o mirar. */
    public function getLinkAction(): ?string
    {
        if ($this->getLinkUrl() === null) {
            return null;
        }

        $action = $this->meta['link_action'] ?? null;

        // Sin acción guardada el botón sigue teniendo que decir algo, y
        // «comprar» es lo que se pidió el 99 % de las veces.
        return in_array($action, self::LINK_ACTIONS, true) ? $action : 'buy';
    }

    /**
     * Pone o quita el enlace directo.
     *
     * Quitar la URL se lleva por delante la acción: una acción sin destino no
     * pinta nada y quedaría ahí esperando a confundir al siguiente que mire.
     */
    public function linkTo(?string $url, ?string $action): void
    {
        $meta = $this->meta ?? [];
        unset($meta['link_url'], $meta['link_action']);

        if ($url !== null && $url !== '') {
            $meta['link_url']    = $url;
            $meta['link_action'] = in_array($action, self::LINK_ACTIONS, true) ? $action : 'buy';
        }

        // `null` y no `[]`: la columna es nullable y un objeto vacío guardado
        // obliga a distinguir dos formas de decir «no hay nada».
        $this->meta      = $meta ?: null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updatePrice(int $amountInMinorUnits, string $isoCurrencyCode): void
    {
        $this->priceAmount   = $amountInMinorUnits;
        $this->priceCurrency = strtoupper($isoCurrencyCode);
        $this->updatedAt     = new \DateTimeImmutable();
    }
}
