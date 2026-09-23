<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure;

/**
 * Lo que la portada de una sala dice de sí misma, para completar su ficha de
 * negocio: avatar, escaparate, descripción y teléfono.
 *
 * Se lee lo que las webs publican para buscadores y redes, que es lo más
 * estable de una maqueta:
 *
 * - **avatar**: el icono de mayor tamaño (`apple-touch-icon` o `icon` con
 *   `sizes`). Es el logo recortado en cuadrado, justo lo que pide un avatar;
 *   el `favicon` de 32 px no vale.
 * - **escaparate**: `og:image`, la imagen que la propia sala eligió para
 *   representarse al compartirla.
 * - **descripción**: `og:description` o `description`.
 * - **teléfono**: `telephone` de los datos estructurados, o el primer `tel:`.
 *
 * Lo que no esté se queda vacío: mejor una ficha incompleta que el panel
 * completa que una inventada.
 */
final class WebsiteProfile
{
    /** Por debajo de esto un icono se ve pixelado como avatar. */
    private const MIN_ICON = 120;

    /** @var array<string, array{avatar: ?string, cover: ?string, description: ?string, phone: ?string}> */
    private array $cache = [];

    public function __construct(private readonly WebPage $web) {}

    /** @return array{avatar: ?string, cover: ?string, description: ?string, phone: ?string} */
    public function of(string $website): array
    {
        if (isset($this->cache[$website])) {
            return $this->cache[$website];
        }

        $empty = ['avatar' => null, 'cover' => null, 'description' => null, 'phone' => null];
        $html  = $this->web->get($website);
        if ($html === null) {
            return $this->cache[$website] = $empty;
        }

        $xp = Html::xpath($html);

        return $this->cache[$website] = [
            'avatar'      => Html::absolute($this->largestIcon($xp), $website),
            'cover'       => Html::absolute(Html::attr($xp, '//meta[@property="og:image"]', 'content'), $website),
            'description' => Html::clean(
                Html::attr($xp, '//meta[@property="og:description"]', 'content')
                    ?? Html::attr($xp, '//meta[@name="description"]', 'content'),
            ),
            'phone'       => $this->phone($html),
        ];
    }

    private function largestIcon(\DOMXPath $xp): ?string
    {
        $best     = null;
        $bestSize = 0;

        foreach ($xp->query('//link[contains(@rel, "icon")]') as $link) {
            if (!$link instanceof \DOMElement || $link->getAttribute('href') === '') {
                continue;
            }

            // `apple-touch-icon` sin `sizes` es de 180 px por convención; un
            // `icon` sin `sizes` suele ser el favicon pequeño.
            $rel  = strtolower($link->getAttribute('rel'));
            $size = preg_match('/(\d+)x\d+/', $link->getAttribute('sizes'), $m)
                ? (int) $m[1]
                : (str_contains($rel, 'apple-touch-icon') ? 180 : 0);

            if ($size > $bestSize) {
                $best     = $link->getAttribute('href');
                $bestSize = $size;
            }
        }

        return $bestSize >= self::MIN_ICON ? $best : null;
    }

    private function phone(string $html): ?string
    {
        if (preg_match('/"telephone"\s*:\s*"([^"]{6,25})"/', $html, $m)
            || preg_match('/href="tel:([+0-9 ().-]{6,25})"/i', $html, $m)
        ) {
            $phone = trim(preg_replace('/[^0-9+]/', '', $m[1]) ?? '');

            return $phone !== '' ? $phone : null;
        }

        return null;
    }
}
