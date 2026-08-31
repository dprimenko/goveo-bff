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
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->logger->info('Bunny video uploaded', ['videoId' => $videoId]);

        return [
            'videoId'   => $videoId,
            'url'       => $this->getVideoUrl($videoId),
            'thumbnail' => $this->getThumbnailUrl($videoId),
        ];
    }

    /** Direct MP4 playback URL (available once transcoded). */
    public function getVideoUrl(string $videoId): string
    {
        return sprintf('https://%s/%s/play_720p.mp4', $this->bunnyCdnHostname, $videoId);
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
