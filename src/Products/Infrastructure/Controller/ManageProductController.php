<?php

declare(strict_types=1);

namespace App\Products\Infrastructure\Controller;

use App\Business\Application\ManagedBusinessFinder;
use App\Products\Domain\ContentFormat;
use App\Products\Domain\Product;
use App\Products\Domain\ProductRepository;
use App\Products\Domain\ProductSubcategoryRepository;
use App\Shared\Domain\UuidGenerator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Alta, edición y baja de productos, para quien gestiona el negocio.
 *
 * La ficha no lleva localización ni categoría propias: **las hereda del
 * negocio**. Un producto suelto de su tienda no significa nada, y duplicar esos
 * campos sólo abriría la puerta a que se contradigan. `category_id` existe en la
 * tabla y se deja intacto —permite que una tienda aparezca en varias categorías
 * de descubrimiento— pero no se toca desde aquí.
 *
 * Las imágenes van por su propio endpoint (ver `ProductImagesController`): son
 * lo único que viaja como `multipart`, y mezclarlas obligaría a que todo el
 * formulario fuera así y a recargar los binarios en cada guardado.
 */
#[Route('/api/businesses/{businessId}/products', name: 'product_manage_')]
class ManageProductController
{
    private const MAX_TITLE = 255;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductSubcategoryRepository $subcategories,
        private readonly ManagedBusinessFinder $managed,
    ) {}

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $businessId, Request $request): Response
    {
        $business = $this->authorize($businessId);
        if ($business instanceof Response) {
            return $business;
        }

        $data  = json_decode($request->getContent(), true) ?? [];
        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '' || mb_strlen($title) > self::MAX_TITLE) {
            return $this->invalid('title_required');
        }

        $price = $this->readPrice($data);
        if ($price instanceof Response) {
            return $price;
        }

        $subcategory = $this->readSubcategory($data, $business->getId());
        if ($subcategory instanceof Response) {
            return $subcategory;
        }

        $product = new Product(
            id:            UuidGenerator::generate(),
            businessId:    $business->getId(),
            title:         $title,
            slug:          $this->uniqueSlug($business->getId(), $title),
            subcategoryId: $subcategory,
            description:   $this->readDescription($data),
            descriptionFormat: $this->readFormat($data),
        );

        if ($price !== null) {
            $product->updatePrice($price['amount'], $price['currency']);
        }

        // Publicado al crearlo: no hay concepto de borrador en la app, y un
        // producto invisible tras darlo de alta se lee como que no se guardó.
        $product->publish();
        $this->products->save($product);

        return new JsonResponse($this->serialize($product), Response::HTTP_CREATED);
    }

    #[Route('/{productId}', name: 'update', methods: ['PATCH'])]
    public function update(string $businessId, string $productId, Request $request): Response
    {
        $business = $this->authorize($businessId);
        if ($business instanceof Response) {
            return $business;
        }

        $product = $this->locateProduct($business->getId(), $productId);
        if ($product === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        // Sólo lo que venga en el cuerpo, como en la edición de negocio: mandar
        // la ficha entera convertiría cada guardado en una reescritura y dos
        // gestores a la vez se pisarían campos que ninguno tocó.
        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '' || mb_strlen($title) > self::MAX_TITLE) {
                return $this->invalid('title_required');
            }
            // El slug **no** cambia al renombrar: es parte de la URL pública y
            // puede estar compartida, igual que con el nombre del negocio.
            $product->rename($title, $product->getSlug());
        }

        if (array_key_exists('description', $data)) {
            $product->describe($this->readDescription($data), $this->readFormat($data));
        }

        if (array_key_exists('subcategory_id', $data)) {
            $subcategory = $this->readSubcategory($data, $business->getId());
            if ($subcategory instanceof Response) {
                return $subcategory;
            }
            $product->moveToSubcategory($subcategory);
        }

        if (array_key_exists('price_amount', $data)) {
            $price = $this->readPrice($data);
            if ($price instanceof Response) {
                return $price;
            }
            if ($price !== null) {
                $product->updatePrice($price['amount'], $price['currency']);
            }
        }

        $this->products->save($product);

        return new JsonResponse($this->serialize($product));
    }

    #[Route('/{productId}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $businessId, string $productId): Response
    {
        $business = $this->authorize($businessId);
        if ($business instanceof Response) {
            return $business;
        }

        $product = $this->locateProduct($business->getId(), $productId);
        if ($product === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        // Baja lógica: las imágenes se quedan en el CDN a propósito. Un borrado
        // por error se deshace desde la base, y perder los ficheros lo haría
        // irreversible.
        $product->softDelete();
        $this->products->save($product);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    // ── Auxiliares ──────────────────────────────────────────────────────────

    /** El negocio, o la respuesta de error si quien pregunta no lo gestiona. */
    private function authorize(string $businessId): mixed
    {
        if (!$this->managed->hasSession()) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // `null` cubre tanto «no existe» como «es de otro»: ver ManagedBusinessFinder.
        return $this->managed->find($businessId)
            ?? new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
    }

    /**
     * El producto de este negocio, si se puede tocar.
     *
     * `findById` devuelve también los dados de baja —la baja es lógica y la fila
     * sigue ahí—, así que hay que descartarlos aquí: sin esto se podía editar un
     * producto ya borrado, y volver a borrarlo respondía 204 como si existiera.
     */
    private function locateProduct(string $businessId, string $productId): ?Product
    {
        $product = $this->products->findById($productId);

        return $product !== null
            && $product->getBusinessId() === $businessId
            && $product->getDeletedAt() === null
                ? $product
                : null;
    }

    private function invalid(string $code): JsonResponse
    {
        return new JsonResponse(['error' => $code], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function readDescription(array $data): ?string
    {
        $description = $data['description'] ?? null;

        return is_string($description) && trim($description) !== '' ? $description : null;
    }

    /**
     * El formato por defecto es HTML y no el `markdown` del enum: lo importado
     * ya viene en HTML, la app lo pinta con `HtmlText` y cualquier editor de
     * texto enriquecido escupe HTML. Poner markdown obligaría a convertir dos
     * veces.
     */
    private function readFormat(array $data): ContentFormat
    {
        return ContentFormat::tryFrom((string) ($data['description_format'] ?? ''))
            ?? ContentFormat::Html;
    }

    /**
     * La subcategoría, comprobando que sea **de este negocio**.
     *
     * Sin la comprobación, un gestor podría colocar su producto en la
     * subcategoría de otra tienda: no se filtraría en la ficha ajena —el
     * listado público cruza negocio y subcategoría— pero dejaría el catálogo
     * apuntando a algo que su dueño puede borrar cuando quiera.
     *
     * @return string|Response|null `null` = fuera de toda subcategoría, que es
     *         un valor válido y la forma de sacar un producto de una.
     */
    private function readSubcategory(array $data, string $businessId): string|Response|null
    {
        $id = $data['subcategory_id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        $subcategory = $this->subcategories->findById($id);
        if ($subcategory === null || $subcategory->getBusinessId() !== $businessId) {
            return $this->invalid('unknown_subcategory');
        }

        return $id;
    }

    /**
     * @return array{amount: int, currency: string}|Response|null `null` = sin
     *         precio (consultar), que es un caso válido.
     */
    private function readPrice(array $data): array|Response|null
    {
        $amount = $data['price_amount'] ?? null;
        if ($amount === null || $amount === '') {
            return null;
        }
        if (!is_numeric($amount) || (int) $amount < 0) {
            return $this->invalid('invalid_price');
        }

        $currency = strtoupper((string) ($data['price_currency'] ?? 'EUR'));
        if (strlen($currency) !== 3) {
            return $this->invalid('invalid_currency');
        }

        return ['amount' => (int) $amount, 'currency' => $currency];
    }

    /**
     * Slug único dentro del negocio: la tabla lo exige (`uq_products_business_slug`)
     * y dos productos con el mismo nombre en una tienda no es raro —«Vino tinto»
     * en dos formatos—, así que se numera en vez de fallar.
     */
    private function uniqueSlug(string $businessId, string $title): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(
            iconv('UTF-8', 'ASCII//TRANSLIT', $title) ?: $title
        )) ?? '', '-') ?: 'producto';

        $slug = $base;
        $n    = 1;
        while ($this->products->findBySlug($businessId, $slug) !== null) {
            $slug = $base . '-' . (++$n);
        }

        return $slug;
    }

    private function serialize(Product $product): array
    {
        return [
            'id'                 => $product->getId(),
            'business_id'        => $product->getBusinessId(),
            'title'              => $product->getTitle(),
            'slug'               => $product->getSlug(),
            'description'        => $product->getDescription(),
            'description_format' => $product->getDescriptionFormat()->value,
            'images'             => $product->imageUrls(),
            'subcategory_id'     => $product->getSubcategoryId(),
            'category_id'        => $product->getCategoryId(),
            'price_amount'       => $product->getPriceAmount(),
            'price_currency'     => $product->getPriceCurrency(),
            'formatted_price'    => $product->getFormattedPrice(),
        ];
    }
}
