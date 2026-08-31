<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Subida de imágenes a Bunny Storage.
 *
 * Distinto de Bunny **Stream**, que es donde van los vídeos: otra API, otras
 * credenciales y otro CDN. Aquí se guardan las imágenes de la ficha del negocio.
 *
 * Estructura: `business/{businessId}/{avatar|main_image}.{ext}`. Una carpeta por
 * negocio hace que borrar su rastro sea borrar una carpeta, y que dos negocios
 * no puedan pisarse los ficheros.
 */
final class BunnyStorageService
{
    /** Lo que aceptamos subir, por lo que dice el contenido y no la extensión. */
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /** 8 MB: una foto de móvil ya recortada cabe de sobra. */
    public const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $zone,
        private readonly string $password,
        private readonly string $host,
        private readonly string $cdnHostname,
    ) {}

    public function isConfigured(): bool
    {
        return $this->zone !== '' && $this->password !== '';
    }

    /**
     * Sube una imagen y devuelve su URL pública.
     *
     * @param string $slot `avatar` o `main_image`
     *
     * @throws StorageException si el contenido no es una imagen admitida,
     *                          pesa de más, o Bunny rechaza la subida
     */
    public function uploadBusinessImage(string $businessId, string $slot, string $contents): string
    {
        if (!$this->isConfigured()) {
            throw new StorageException('El almacenamiento de imágenes no está configurado.');
        }

        $size = strlen($contents);
        if ($size === 0) {
            throw new StorageException('El fichero está vacío.');
        }
        if ($size > self::MAX_BYTES) {
            throw new StorageException(sprintf(
                'La imagen pesa %d MB y el máximo son %d MB.',
                (int) ceil($size / 1024 / 1024),
                self::MAX_BYTES / 1024 / 1024,
            ));
        }

        // Se mira el contenido, no la extensión ni lo que diga el cliente: un
        // `.jpg` puede ser cualquier cosa.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: '';
        if (!isset(self::ALLOWED[$mime])) {
            throw new StorageException(sprintf('Formato no admitido (%s). Usa JPG, PNG o WebP.', $mime));
        }

        // El nombre lleva marca de tiempo para saltarse la caché del CDN: sin
        // ella, cambiar la foto dejaría la vieja a la vista durante horas.
        $path = sprintf(
            'business/%s/%s-%d.%s',
            $businessId,
            $slot,
            time(),
            self::ALLOWED[$mime],
        );

        $response = $this->httpClient->request(
            'PUT',
            sprintf('https://%s/%s/%s', $this->host, $this->zone, $path),
            [
                'headers' => [
                    'AccessKey'    => $this->password,
                    'Content-Type' => 'application/octet-stream',
                ],
                'body' => $contents,
            ],
        );

        if ($response->getStatusCode() >= 300) {
            $this->logger->error('Bunny Storage rechazó la subida', [
                'status' => $response->getStatusCode(),
                'path'   => $path,
            ]);

            throw new StorageException('No se pudo guardar la imagen.');
        }

        return sprintf('https://%s/%s', $this->cdnHostname, $path);
    }

    /**
     * Borra una imagen anterior. **No lanza**: es limpieza, y que falle no debe
     * tumbar un guardado que ya ha ido bien.
     */
    public function deleteByUrl(?string $url): void
    {
        if ($url === null || !$this->isConfigured()) {
            return;
        }

        $prefix = sprintf('https://%s/', $this->cdnHostname);
        if (!str_starts_with($url, $prefix)) {
            // De Cloudinary o de fuera: no es nuestro, no se toca.
            return;
        }

        try {
            $this->httpClient->request(
                'DELETE',
                sprintf('https://%s/%s/%s', $this->host, $this->zone, substr($url, strlen($prefix))),
                ['headers' => ['AccessKey' => $this->password]],
            )->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->warning('No se pudo borrar una imagen antigua', [
                'url'     => $url,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
