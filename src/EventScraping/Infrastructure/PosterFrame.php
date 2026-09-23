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
 * **No se amplía**: el lienzo se hace a la medida del original. Un cartel de
 * 630×400 estirado a 1080 de ancho sale borroso, y la pantalla lo va a escalar
 * igual. Sólo se reduce lo que pasa de 1080×1920, que es más de lo que pinta
 * cualquier móvil.
 */
final class PosterFrame
{
    private const MAX_WIDTH = 1080;
    private const QUALITY   = 88;

    /**
     * @return string JPEG ya encuadrado
     *
     * @throws \RuntimeException si no es una imagen que GD sepa leer
     */
    public function fit(string $contents): string
    {
        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            throw new \RuntimeException('formato de imagen que no se puede leer');
        }

        $w = imagesx($source);
        $h = imagesy($source);

        // El lienzo más pequeño en 9:16 que contiene el cartel entero…
        $canvasW = max($w, (int) ceil($h * 9 / 16));
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
}
