<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use App\Products\Domain\ProductRepository;
use App\Shared\Infrastructure\Storage\BunnyStorageService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * El catálogo de un negocio, visto desde el panel.
 *
 * `GET  /api/admin/businesses/{id}/products?status=&q=&page=&size=`
 * `GET  /api/admin/products?status=&q=&business=&page=&size=` (todas las tiendas)
 * `PUT  /api/admin/businesses/{id}/products/{productId}/{publish|unpublish|remove|restore}`
 * `DELETE /api/admin/businesses/{id}/products/{productId}` (definitivo)
 *
 * Un producto tiene **dos estados independientes** y conviene no confundirlos:
 * `published_at` decide si se ve en la tienda —sin ella es un borrador de su
 * dueño— y `deleted_at`, si existe. Se pueden dar a la vez: un borrador borrado
 * es un borrador borrado.
 *
 * Lo que se ofrece aquí es mirar y quitar de en medio, no editar: el catálogo lo
 * mantiene su dueño desde la app, y un panel que además edita productos acaba
 * pisando lo que la tienda acaba de cambiar.
 */
#[IsGranted('ROLE_BUSINESS_EDIT')]
class BusinessProductsController
{
    private const DEFAULT_SIZE = 24;
    private const MAX_SIZE     = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly ProductRepository $products,
        private readonly BunnyStorageService $storage,
    ) {}

    #[Route('/api/admin/businesses/{id}/products', name: 'admin_business_products', methods: ['GET'])]
    public function list(string $id, Request $request): Response
    {
        return $this->listWhere($request, 'p.business_id = ?', [$id]);
    }

    /**
     * Los productos de todas las tiendas, con la suya al lado. Para buscar uno
     * sin saber de quién es, y para vaciar la papelera de todo el catálogo de
     * una vez. `q` busca también en el nombre del negocio; `business` acota a
     * uno.
     */
    #[Route('/api/admin/products', name: 'admin_products', methods: ['GET'])]
    public function listAll(Request $request): Response
    {
        $business = trim((string) $request->query->get('business', ''));

        return $business === ''
            ? $this->listWhere($request, 'TRUE', [], withBusiness: true)
            : $this->listWhere($request, 'p.business_id = ?', [$business], withBusiness: true);
    }

    private function listWhere(Request $request, string $scope, array $params, bool $withBusiness = false): Response
    {
        $status = (string) $request->query->get('status', 'published');
        $page   = max(1, (int) $request->query->get('page', 1));
        $size   = min(self::MAX_SIZE, max(1, (int) $request->query->get('size', self::DEFAULT_SIZE)));
        $q      = trim((string) $request->query->get('q', ''));

        $condition = match ($status) {
            'published' => 'p.deleted_at IS NULL AND p.published_at IS NOT NULL',
            'draft'     => 'p.deleted_at IS NULL AND p.published_at IS NULL',
            'removed'   => 'p.deleted_at IS NOT NULL',
            default     => null,
        };

        if ($condition === null) {
            return new JsonResponse(
                ['error' => 'Unknown status. Use published, draft or removed.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $where = "{$scope} AND ({$condition})";

        if ($q !== '') {
            $where .= $withBusiness
                ? ' AND (unaccent(lower(p.title)) LIKE unaccent(lower(?)) OR unaccent(lower(b.name)) LIKE unaccent(lower(?)))'
                : ' AND unaccent(lower(p.title)) LIKE unaccent(lower(?))';
            $params[] = '%' . $q . '%';
            if ($withBusiness) {
                $params[] = '%' . $q . '%';
            }
        }

        $from = 'FROM products p
                 LEFT JOIN product_subcategories sc ON sc.id = p.subcategory_id
                 LEFT JOIN business b ON b.id = p.business_id';

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) {$from} WHERE {$where}", $params);

        // En la papelera, lo último borrado arriba: es lo que se busca al
        // arrepentirse.
        $order = $status === 'removed' ? 'p.deleted_at DESC' : 'p.created_at DESC';

        $rows = $this->db->fetchAllAssociative(
            "SELECT p.id, p.business_id, p.title, p.slug, p.images, p.price_amount, p.price_currency,
                    p.description, p.subcategory_id, p.meta,
                    p.created_at, p.published_at, p.deleted_at, sc.name AS subcategory,
                    b.name AS business_name, b.avatar AS business_avatar, b.deleted_at AS business_deleted_at
               {$from}
              WHERE {$where}
              ORDER BY {$order}, p.id
              LIMIT ? OFFSET ?",
            [...$params, $size, ($page - 1) * $size],
        );

        return new JsonResponse([
            'items' => array_map([$this, 'toItem'], $rows),
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
        ]);
    }

    #[Route(
        '/api/admin/businesses/{id}/products/{productId}/{action}',
        name: 'admin_business_product_action',
        requirements: ['action' => 'publish|unpublish|remove|restore'],
        methods: ['PUT'],
    )]
    public function act(string $id, string $productId, string $action): Response
    {
        $product = $this->products->findById($productId);

        // También se comprueba el negocio: sin esto, el id de un producto de
        // otra tienda colado en la URL se gestionaría igual.
        if ($product === null || $product->getBusinessId() !== $id) {
            return new JsonResponse(['error' => 'Product not found.'], Response::HTTP_NOT_FOUND);
        }

        match ($action) {
            'publish'   => $product->publish(),
            'unpublish' => $product->unpublish(),
            'remove'    => $product->softDelete(),
            'restore'   => $product->restore(),
        };

        $this->products->save($product);

        return new JsonResponse([
            'id'           => $product->getId(),
            'published_at' => $product->getPublishedAt()?->format(\DATE_ATOM),
            'deleted_at'   => $product->getDeletedAt()?->format(\DATE_ATOM),
        ]);
    }

    /**
     * Definitivo: la fila y sus imágenes. Sólo sobre lo ya borrado, como en el
     * resto del panel, para que ningún clic de más acabe en algo irrecuperable.
     */
    #[Route(
        '/api/admin/businesses/{id}/products/{productId}',
        name: 'admin_business_product_purge',
        methods: ['DELETE'],
    )]
    #[IsGranted('ROLE_BUSINESS_DELETE')]
    public function purge(string $id, string $productId): Response
    {
        $product = $this->products->findById($productId);

        if ($product === null || $product->getBusinessId() !== $id) {
            return new JsonResponse(['error' => 'Product not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$product->isDeleted()) {
            return new JsonResponse(
                ['error' => 'Only already-deleted products can be purged.'],
                Response::HTTP_CONFLICT,
            );
        }

        // Primero las imágenes: si fallara después el borrado de la fila,
        // quedaría un producto sin fotos —del que se puede salir—; al revés
        // quedarían fotos pagándose sin nada que las nombre.
        $storageDeleted = $this->storage->deleteProductFolder($id, $productId);

        $this->db->executeStatement('DELETE FROM products WHERE id = ?', [$productId]);

        return new JsonResponse(['storage_deleted' => $storageDeleted]);
    }

    private function toItem(array $row): array
    {
        $images = json_decode((string) ($row['images'] ?? ''), true) ?: [];
        // Por su `order`, como `Product::imageUrls()`: guardadas pueden estar en
        // cualquier orden, y la portada es la primera de la lista ordenada.
        usort($images, static fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        $meta   = json_decode((string) ($row['meta'] ?? ''), true) ?: [];

        return [
            'id'    => $row['id'],
            'business' => [
                'id'     => $row['business_id'],
                'name'   => $row['business_name'],
                'avatar' => $row['business_avatar'],
                // Un producto de una tienda archivada no se recupera solo: al
                // recuperarlo seguiría sin verse hasta recuperar la tienda.
                'removed' => $row['business_deleted_at'] !== null,
            ],
            'title' => $row['title'],
            'slug'  => $row['slug'],
            // La primera imagen es la portada del producto en la tienda.
            'image' => $images[0]['url'] ?? null,
            'price' => $row['price_amount'] === null ? null : [
                'amount'   => (int) $row['price_amount'],
                'currency' => $row['price_currency'] ?? 'EUR',
            ],
            'subcategory'  => $row['subcategory'],
            // Lo que necesita el formulario de edición del panel, que no tiene
            // otra forma de leer un producto entero.
            'subcategory_id' => $row['subcategory_id'],
            'description'    => $row['description'],
            'images'         => array_values(array_filter(array_map(
                static fn ($i) => is_array($i) ? ($i['url'] ?? null) : null,
                $images,
            ))),
            'link_url'       => $meta['link_url'] ?? null,
            'link_action'    => $meta['link_action'] ?? null,
            'created_at'   => self::iso($row['created_at']),
            'published_at' => self::iso($row['published_at']),
            'deleted_at'   => self::iso($row['deleted_at']),
        ];
    }

    private static function iso(?string $timestamp): ?string
    {
        return $timestamp === null ? null : (new \DateTimeImmutable($timestamp))->format(\DATE_ATOM);
    }
}
