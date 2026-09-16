<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bunny Stream client. The backend proxies the upload: it creates the video
 * object in the library, then PUTs the raw binary. Auth is the library
 * `AccessKey` header (no signed URLs are exposed to clients).
 *
 * Playback + thumbnail URLs are deterministic from the video GUID, so they can
 * be stored at creation time; Bunny serves them once transcoding finishes.
 */
final class BunnyVideoService
{
    private const API_URL = 'https://video.bunnycdn.com';

    /** Lo que se asume mientras no se sepa qué calidades hay. */
    private const DEFAULT_RESOLUTION = '720p';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $bunnyApiKey,
        private readonly string $bunnyLibraryId,
        private readonly string $bunnyCdnHostname,
    ) {}

    /**
     * Create the video object and upload the binary.
     *
     * @return array{videoId: string, url: string, thumbnail: string}
     */
    public function uploadVideo(File $file, string $title): array
    {
        // 1) Create the video object → get the GUID.
        $create = $this->http->request('POST', sprintf('%s/library/%s/videos', self::API_URL, $this->bunnyLibraryId), [
            'headers' => [
                'AccessKey'    => $this->bunnyApiKey,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'json' => ['title' => $title],
        ]);

        $videoId = $create->toArray()['guid'] ?? null;
        if (!is_string($videoId) || $videoId === '') {
            throw new \RuntimeException('Bunny: could not create video object (no guid).');
        }

        // 2) Upload the binary.
        $stream = fopen($file->getPathname(), 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Bunny: could not open uploaded file for reading.');
        }

        $size = $file->getSize();

        try {
            $put = $this->http->request('PUT', sprintf('%s/library/%s/videos/%s', self::API_URL, $this->bunnyLibraryId, $videoId), [
                'headers' => [
                    'AccessKey'    => $this->bunnyApiKey,
                    'Content-Type' => 'application/octet-stream',
                ],
                'body' => $stream,
            ]);

            $status = $put->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(sprintf('Bunny: binary upload failed (%d).', $status));
            }
        } catch (\Throwable $e) {
            // Sin binario, el objeto creado no sirve para nada: se queda en la
            // librería en «procesando» y no hay quien lo distinga de una subida
            // en curso. Se borra aquí y el error sube tal cual.
            $this->deleteVideo($videoId);

            throw $e;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->logger->info('Bunny video uploaded', ['videoId' => $videoId, 'bytes' => $size]);

        return [
            'videoId'   => $videoId,
            'url'       => $this->getVideoUrl($videoId),
            'thumbnail' => $this->getThumbnailUrl($videoId),
        ];
    }

    /** Direct MP4 playback URL (available once transcoded). */
    public function getVideoUrl(string $videoId, string $resolution = self::DEFAULT_RESOLUTION): string
    {
        return sprintf('https://%s/%s/play_%s.mp4', $this->bunnyCdnHostname, $videoId, $resolution);
    }

    /**
     * La URL de reproducción que **de verdad existe**.
     *
     * Bunny sólo codifica hacia abajo: de un vídeo grabado a 854 de lado largo
     * no sale un 720p, y pedirlo devuelve **404**. Como la URL se guardaba fija
     * en `play_720p.mp4`, esos vídeos quedaban con un enlace muerto —se notó en
     * el aviso interno de vídeo nuevo, donde el enlace no abría nada—.
     *
     * Se sigue prefiriendo 720p, que es lo que se venía sirviendo; lo único que
     * cambia es que, si no está, se baja a la mejor que haya en vez de enlazar
     * a un fichero inexistente. Subir a 1080p sería otra decisión —más ancho de
     * banda para todo el mundo— y no es lo que había que arreglar.
     *
     * Si el máster no se puede leer se devuelve el 720p de siempre: es mejor
     * dejar el enlace que había que dejar el vídeo sin URL.
     */
    public function getBestVideoUrl(string $videoId): string
    {
        return $this->getVideoUrl($videoId, $this->bestResolution($videoId) ?? self::DEFAULT_RESOLUTION);
    }

    /**
     * La mejor calidad disponible sin pasar de la preferida.
     *
     * @param ?string $hostname el CDN donde vive **ese** vídeo. Los importados
     *        están en otra librería que la configurada, así que para repasarlos
     *        hay que ir al servidor que diga su URL y no al del entorno.
     *
     * @return ?string p. ej. `480p`, o `null` si no se pudo leer el máster.
     */
    public function bestResolution(string $videoId, ?string $hostname = null): ?string
    {
        $disponibles = $this->availableResolutions($videoId, $hostname);
        if ($disponibles === []) {
            return null;
        }

        $tope   = (int) self::DEFAULT_RESOLUTION;
        $cabidas = array_filter($disponibles, static fn (int $r) => $r <= $tope);

        // Sin ninguna por debajo del tope se coge la más pequeña que haya: el
        // objetivo es que el enlace abra, no servir lo más grande posible.
        return (empty($cabidas) ? min($disponibles) : max($cabidas)) . 'p';
    }

    /**
     * Las calidades que Bunny generó, leídas del **máster HLS**: es público y no
     * necesita clave, y cada variante vive en `<calidad>/video.m3u8`, que es
     * literalmente el nombre que lleva el MP4. Preguntar por la API sería otra
     * llamada autenticada para la misma respuesta.
     *
     * @return int[] p. ej. `[1080, 720, 480]`
     */
    public function availableResolutions(string $videoId, ?string $hostname = null): array
    {
        try {
            $master = $this->http->request('GET', sprintf('https://%s/%s/playlist.m3u8', $hostname ?? $this->bunnyCdnHostname, $videoId), [
                'timeout' => 5,
            ])->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Bunny: no se pudo leer el máster HLS', ['videoId' => $videoId, 'error' => $e->getMessage()]);

            return [];
        }

        if (!preg_match_all('~^\s*(\d+)p/~m', $master, $matches)) {
            return [];
        }

        $resoluciones = array_map('intval', $matches[1]);
        rsort($resoluciones);

        return $resoluciones;
    }

    /** Auto-generated thumbnail URL. */
    public function getThumbnailUrl(string $videoId): string
    {
        return sprintf('https://%s/%s/thumbnail.jpg', $this->bunnyCdnHostname, $videoId);
    }

    public function getLibraryId(): string
    {
        return $this->bunnyLibraryId;
    }

    /**
     * Current transcoding status of a video (0-3 in-flight, 4 finished,
     * 5 failed), or null if it can't be fetched. Used to self-heal geostories
     * stuck in `processing` when the webhook was missed (e.g. a dead dev tunnel).
     */
    public function getVideoStatus(string $videoId): ?int
    {
        try {
            $res = $this->http->request('GET', sprintf('%s/library/%s/videos/%s', self::API_URL, $this->bunnyLibraryId, $videoId), [
                'headers' => ['AccessKey' => $this->bunnyApiKey, 'accept' => 'application/json'],
            ]);
            $data = $res->toArray(false);

            return isset($data['status']) ? (int) $data['status'] : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Bunny: status fetch failed', ['videoId' => $videoId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function deleteVideo(string $videoId): void
    {
        try {
            $this->http->request('DELETE', sprintf('%s/library/%s/videos/%s', self::API_URL, $this->bunnyLibraryId, $videoId), [
                'headers' => ['AccessKey' => $this->bunnyApiKey],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Bunny: delete failed', ['videoId' => $videoId, 'error' => $e->getMessage()]);
        }
    }
}
