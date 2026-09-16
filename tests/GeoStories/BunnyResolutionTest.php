<?php

declare(strict_types=1);

namespace App\Tests\GeoStories;

use App\GeoStories\Infrastructure\Service\BunnyVideoService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Qué calidad se enlaza.
 *
 * Bunny sólo codifica hacia abajo, así que `play_720p.mp4` —que es lo que se
 * guardaba siempre— devuelve 404 en cuanto el original no da para tanto. Las
 * calidades que existen se leen del máster HLS, donde cada variante vive en
 * `<calidad>/video.m3u8`.
 */
final class BunnyResolutionTest extends TestCase
{
    public function testItKeepsThePreferredQualityWhenItExists(): void
    {
        $bunny = $this->bunny($this->master(['1080p', '720p', '480p']));

        // Habiendo 1080p se sigue enlazando el 720p: lo que se arregla es el
        // enlace roto, no la calidad que se venía sirviendo.
        self::assertSame('720p', $bunny->bestResolution('vid'));
    }

    public function testItFallsBackToTheBestQualityBelowThePreferredOne(): void
    {
        $bunny = $this->bunny($this->master(['480p', '360p', '240p']));

        self::assertSame('480p', $bunny->bestResolution('vid'));
        self::assertSame(
            'https://cdn.test/vid/play_480p.mp4',
            $bunny->getBestVideoUrl('vid'),
        );
    }

    public function testWithNothingBelowThePreferredOneItTakesTheSmallestThereIs(): void
    {
        // No debería pasar —Bunny genera la escalera hacia abajo—, pero el
        // objetivo es que el enlace abra: inventarse un 720p que no está sería
        // volver al fallo.
        $bunny = $this->bunny($this->master(['1080p']));

        self::assertSame('1080p', $bunny->bestResolution('vid'));
    }

    public function testAnUnreadableMasterLeavesThePreferredQuality(): void
    {
        // Mejor el enlace de siempre que un vídeo sin URL.
        $bunny = $this->bunny(static fn (): MockResponse => new MockResponse('', ['http_code' => 403]));

        self::assertNull($bunny->bestResolution('vid'));
        self::assertSame('https://cdn.test/vid/play_720p.mp4', $bunny->getBestVideoUrl('vid'));
    }

    /** @param string[] $calidades */
    private function master(array $calidades): \Closure
    {
        $lineas = ['#EXTM3U', '#EXT-X-VERSION:4'];
        foreach ($calidades as $calidad) {
            $lineas[] = '#EXT-X-STREAM-INF:BANDWIDTH=1408119,RESOLUTION=476x854';
            $lineas[] = $calidad . '/video.m3u8';
        }

        // Una respuesta nueva por petición: el mismo test pide el máster dos
        // veces y `MockHttpClient` consume las respuestas que se le dan sueltas.
        return static fn (): MockResponse => new MockResponse(implode("\n", $lineas));
    }

    private function bunny(\Closure $master): BunnyVideoService
    {
        return new BunnyVideoService(
            new MockHttpClient($master),
            new NullLogger(),
            'clave',
            'biblioteca',
            'cdn.test',
        );
    }
}
