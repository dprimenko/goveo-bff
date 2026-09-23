<?php

declare(strict_types=1);

namespace App\Tests\EventScraping;

use App\EventScraping\Infrastructure\PosterFrame;
use PHPUnit\Framework\TestCase;

/**
 * **El cartel entra entero y en vertical.** Lo que importa no se ve en una sola
 * captura: que uno horizontal lleva las bandas arriba y abajo y uno muy alto a
 * los lados, que no se amplía lo pequeño y que sí se reduce lo enorme.
 */
final class PosterFrameTest extends TestCase
{
    /** Una imagen blanca de ese tamaño, en PNG. */
    private function image(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /** @return array{0: \GdImage, 1: int, 2: int} */
    private function framed(int $w, int $h): array
    {
        $out = imagecreatefromstring((new PosterFrame())->fit($this->image($w, $h)));
        self::assertNotFalse($out);

        return [$out, imagesx($out), imagesy($out)];
    }

    private function isBlack(\GdImage $img, int $x, int $y): bool
    {
        $rgb = imagecolorat($img, $x, $y);

        return (($rgb >> 16) & 0xFF) < 20 && (($rgb >> 8) & 0xFF) < 20 && ($rgb & 0xFF) < 20;
    }

    public function testALandscapePosterGetsBandsAboveAndBelow(): void
    {
        // Como los de Café Berlín: 630×400.
        [$img, $w, $h] = $this->framed(630, 400);

        self::assertSame(630, $w, 'no se amplía');
        self::assertSame(1120, $h, '9:16');
        self::assertTrue($this->isBlack($img, 315, 10), 'banda arriba');
        self::assertTrue($this->isBlack($img, 315, $h - 10), 'banda abajo');
        self::assertFalse($this->isBlack($img, 5, $h / 2), 'sin bandas a los lados');
    }

    public function testAVeryTallPosterGetsBandsOnTheSides(): void
    {
        [$img, $w, $h] = $this->framed(400, 1000);

        self::assertSame(563, $w);
        self::assertSame(1001, $h);
        self::assertTrue($this->isBlack($img, 5, (int) ($h / 2)), 'banda a la izquierda');
        self::assertTrue($this->isBlack($img, $w - 5, (int) ($h / 2)), 'banda a la derecha');
        self::assertFalse($this->isBlack($img, (int) ($w / 2), 5), 'sin bandas arriba');
    }

    public function testAHugePosterIsScaledDown(): void
    {
        [, $w, $h] = $this->framed(4000, 3000);

        self::assertSame(1080, $w);
        self::assertSame(1920, $h);
    }

    public function testAnImageTooBigToOpenIsRefusedBeforeOpeningIt(): void
    {
        // Sólo la cabecera de un PNG de 20000×20000: abrirlo de verdad serían
        // ~2 GB. Tiene que rechazarse leyendo las medidas, sin llegar a GD —si
        // llegara, el test moriría por memoria en vez de fallar—.
        $ihdr = pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0);
        $png  = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/demasiado grande \(20000×20000/');
        (new PosterFrame())->fit($png);
    }

    public function testSomethingThatIsNotAnImageIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PosterFrame())->fit('<html>no es una imagen</html>');
    }
}
