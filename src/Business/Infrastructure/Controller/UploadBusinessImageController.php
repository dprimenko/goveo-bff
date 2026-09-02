<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Controller;

use App\Business\Application\ManagedBusinessFinder;
use App\Shared\Infrastructure\Storage\BunnyStorageService;
use App\Shared\Infrastructure\Storage\StorageException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sube la portada o el logo de un negocio.
 *
 * Va aparte del `PATCH` de la ficha porque es lo único que se manda como
 * `multipart`: mezclar un binario con el resto de campos obligaría a que todo el
 * formulario viajara así, y a cargar la imagen en memoria en cada guardado
 * aunque no haya cambiado.
 *
 * Guarda la URL en el negocio y **borra la anterior**, para no ir dejando fotos
 * huérfanas cada vez que alguien cambia su portada.
 */
#[Route('/api/businesses/{id}/images/{slot}', name: 'business_image_upload', methods: ['POST'])]
class UploadBusinessImageController
{
    private const SLOTS = ['avatar', 'main_image'];

    public function __construct(
        private readonly ManagedBusinessFinder $managed,
        private readonly BunnyStorageService $storage,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(string $id, string $slot, Request $request): Response
    {
        if (!in_array($slot, self::SLOTS, true)) {
            return new JsonResponse(['error' => 'unknown_slot'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->managed->hasSession()) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // `null` es tanto «no existe» como «es de otro»: un 403 confirmaría que
        // ese id está dado de alta.
        $business = $this->managed->find($id);
        if ($business === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $file = $request->files->get('file');
        if ($file === null) {
            return new JsonResponse(['error' => 'file_required'], Response::HTTP_BAD_REQUEST);
        }
        if (!$file->isValid()) {
            // Lo más común aquí es que PHP haya cortado por `upload_max_filesize`.
            return new JsonResponse(
                ['error' => 'upload_failed', 'detail' => $file->getErrorMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $previous = $slot === 'avatar' ? $business->getAvatar() : $business->getMainImage();

        try {
            $url = $this->storage->uploadBusinessImage(
                $business->getId(),
                $slot,
                (string) file_get_contents($file->getPathname()),
            );
        } catch (StorageException $e) {
            return new JsonResponse(
                ['error' => 'invalid_image', 'detail' => $e->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Fallo subiendo imagen de negocio: {message}', [
                'message'  => $e->getMessage(),
                'business' => $business->getId(),
            ]);

            return new JsonResponse(['error' => 'upload_failed'], Response::HTTP_BAD_GATEWAY);
        }

        $slot === 'avatar' ? $business->setAvatar($url) : $business->setMainImage($url);
        $this->businesses->save($business);

        // Después de guardar: si se borrara antes y fallara el guardado, el
        // negocio se quedaría apuntando a una imagen que ya no existe.
        $this->storage->deleteByUrl($previous);

        return new JsonResponse(['url' => $url, 'slot' => $slot], Response::HTTP_CREATED);
    }
}
