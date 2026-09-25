<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure;

/**
 * Encaja un cartel en vertical, 9:16, con bandas negras: la app enseña las
 * historias a pantalla completa en el móvil, y un cartel horizontal se quedaba
 * recortado por los lados justo donde suelen ir el nombre y la fecha.
 *
 * **Se encaja, no se recorta**: el cartel entero, centrado. Si es más ancho que
 * 9:16, bandas arriba y abajo; si es más estrecho, a los lados.
 *
 * **Y siempre con margen a los lados** (`SIDE_MARGIN`): pegado a los bordes, el
 * cartel ocupaba todo el ancho del móvil y se leía peor. Lo pidió el socio.
 *
 * **No se amplía**: el lienzo se hace a la medida del original. Un cartel de
 * 630×400 estirado a 1080 de ancho sale borroso, y la pantalla lo va a escalar
 * igual. Sólo se reduce lo que pasa de 1080×1920, que es más de lo que pinta
 * cualquier móvil.
 */
final class PosterFrame
{
    private const MAX_WIDTH = 1080;
    private const QUALITY   = 88;

    /** Negro a cada lado, en fracción del ancho del lienzo. */
    private const SIDE_MARGIN = 0.08;

    /**
     * @return string JPEG ya encuadrado
     *
     * @throws \RuntimeException si no es una imagen que GD sepa leer
     */
    public function fit(string $contents): string
    {
        $this->assertFitsInMemory($contents);

        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            throw new \RuntimeException('formato de imagen que no se puede leer');
        }

        $w = imagesx($source);
        $h = imagesy($source);

        // El lienzo más pequeño en 9:16 que contiene el cartel entero con su
        // margen a los lados…
        $canvasW = max((int) ceil($w / (1 - 2 * self::SIDE_MARGIN)), (int) ceil($h * 9 / 16));
        // …y, si pasa del máximo, todo reducido en proporción.
        $scale   = min(1.0, self::MAX_WIDTH / $canvasW);
        $canvasW = (int) round($canvasW * $scale);
        $canvasH = (int) round($canvasW * 16 / 9);
        $drawW   = max(1, (int) round($w * $scale));
        $drawH   = max(1, (int) round($h * $scale));

        $canvas = imagecreatetruecolor($canvasW, $canvasH);
        // Negro también bajo lo transparente de un PNG: el JPEG no tiene
        // transparencia y sin esto saldría gris o con halos.
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 0, 0, 0));
        imagecopyresampled(
            $canvas,
            $source,
            intdiv($canvasW - $drawW, 2),
            intdiv($canvasH - $drawH, 2),
            0,
            0,
            $drawW,
            $drawH,
            $w,
            $h,
        );

        ob_start();
        imagejpeg($canvas, null, self::QUALITY);
        $jpeg = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        return $jpeg;
    }

    /**
     * Mira si abrir la imagen cabe en la memoria que queda, **antes** de abrirla.
     *
     * GD descomprime el original entero —unos 5 bytes por píxel—, así que una
     * foto de 6000×8000 son ~240 MB sólo para empezar: pasaba de los 256 MB de
     * PHP y tumbaba la pasada entera con un fatal, que no se puede capturar.
     * Leer las dimensiones no descomprime nada. Si no cabe, se lanza una
     * excepción normal y el importador descarta ese evento y sigue.
     */
    private function assertFitsInMemory(string $contents): void
    {
        $size = @getimagesizefromstring($contents);
        if ($size === false) {
            throw new \RuntimeException('formato de imagen que no se puede leer');
        }

        [$w, $h] = $size;
        // El original, el lienzo de salida y el JPEG en memoria, con margen.
        $needed    = (int) (($w * $h * 5 + self::MAX_WIDTH * 1920 * 5) * 1.3);
        $available = $this->memoryLimit() - memory_get_usage(true);

        if ($needed > $available) {
            throw new \RuntimeException(sprintf(
                'imagen demasiado grande (%d×%d, necesita %d MB y quedan %d MB)',
                $w,
                $h,
                intdiv($needed, 1024 * 1024),
                intdiv(max(0, $available), 1024 * 1024),
            ));
        }
    }

    private function memoryLimit(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return \PHP_INT_MAX;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g'     => $value * 1024 ** 3,
            'm'     => $value * 1024 ** 2,
            'k'     => $value * 1024,
            default => $value,
        };
    }
}
