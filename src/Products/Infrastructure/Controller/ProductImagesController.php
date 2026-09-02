<?php

declare(strict_types=1);

namespace App\Products\Infrastructure\Controller;

use App\Business\Application\ManagedBusinessFinder;
use App\Products\Domain\Product;
use App\Products\Domain\ProductRepository;
use App\Shared\Infrastructure\Storage\BunnyStorageService;
use App\Shared\Infrastructure\Storage\StorageException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Imágenes de un producto: hasta cuatro, de una en una.
 *
 * De una en una y no todas de golpe porque así una foto que falle no tumba las
 * otras tres, y la app puede ir enseñando el progreso. El tope lo impone la
 * entidad (`Product::MAX_IMAGES`), no este controlador.
 *
 * Va aparte del alta y la edición por lo mismo que en el negocio: es lo único
 * que viaja como `multipart`.
 */
#[Route('/api/businesses/{businessId}/products/{productId}/images', name: 'product_images_')]
class ProductImagesController
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ManagedBusinessFinder $managed,
        private readonly BunnyStorageService $storage,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', name: 'add', methods: ['POST'])]
    public function add(string $businessId, string $productId, Request $request): Response
    {
        $product = $this->locate($businessId, $productId);
        if ($product instanceof Response) {
            return $product;
        }

        $file = $request->files->get('file');
        if ($file === null) {
            return new JsonResponse(['error' => 'file_required'], Response::HTTP_BAD_REQUEST);
        }
        if (!$file->isValid()) {
            // Casi siempre es PHP cortando por `upload_max_filesize`.
            return new JsonResponse(
                ['error' => 'upload_failed', 'detail' => $file->getErrorMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (count($product->getImages() ?? []) >= Product::MAX_IMAGES) {
            return new JsonResponse(
                ['error' => 'too_many_images', 'max' => Product::MAX_IMAGES],
                Response::HTTP_CONFLICT,
            );
        }

        // La ruta imita a la del import para que todo el catálogo viva igual:
        // business/{negocio}/products/{producto}/{orden}-{marca de tiempo}.{ext}
        $index = count($product->getImages() ?? []);
        $path  = fn (string $ext): string => sprintf(
            'business/%s/products/%s/%d-%d.%s',
            $product->getBusinessId(),
            $product->getId(),
            $index,
            time(),
            $ext,
        );

        try {
            $url = $this->storage->upload($path, (string) file_get_contents($file->getPathname()));
        } catch (StorageException $e) {
            return new JsonResponse(
                ['error' => 'invalid_image', 'detail' => $e->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Fallo subiendo imagen de producto: {message}', [
                'message' => $e->getMessage(),
                'product' => $product->getId(),
            ]);

            return new JsonResponse(['error' => 'upload_failed'], Response::HTTP_BAD_GATEWAY);
        }

        $product->addImage($url);
        $this->products->save($product);

        return new JsonResponse(
            ['url' => $url, 'images' => $product->imageUrls()],
            Response::HTTP_CREATED,
        );
    }

    #[Route('', name: 'remove', methods: ['DELETE'])]
    public function remove(string $businessId, string $productId, Request $request): Response
    {
        $product = $this->locate($businessId, $productId);
        if ($product instanceof Response) {
            return $product;
        }

        $url = (string) ((json_decode($request->getContent(), true) ?? [])['url'] ?? '');
        if ($url === '' || !$product->removeImage($url)) {
            return new JsonResponse(['error' => 'image_not_found'], Response::HTTP_NOT_FOUND);
        }

        $this->products->save($product);

        // Después de guardar: al revés, si el guardado fallara el producto se
        // quedaría apuntando a un fichero que ya no existe.
        $this->storage->deleteByUrl($url);

        return new JsonResponse(['images' => $product->imageUrls()]);
    }

    /** El producto, o la respuesta de error si no se puede tocar. */
    private function locate(string $businessId, string $productId): mixed
    {
        if (!$this->managed->hasSession()) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $business = $this->managed->find($businessId);
        if ($business === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        // `findById` no filtra las bajas lógicas: un producto borrado no debe
        // admitir imágenes nuevas.
        $product = $this->products->findById($productId);
        if (
            $product === null
            || $product->getBusinessId() !== $business->getId()
            || $product->getDeletedAt() !== null
        ) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return $product;
    }
}
