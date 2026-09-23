<?php

declare(strict_types=1);

namespace App\Products\Application;

use App\Products\Domain\ProductRepository;

/**
 * El slug de un producto dentro de su negocio, único.
 *
 * Dos productos con el mismo nombre en una tienda no es raro —«Vino tinto» en
 * dos formatos—, así que al segundo se le numera en vez de fallar. La tabla lo
 * exige con el índice `uq_products_business_slug`.
 *
 * **Cuentan también los borrados.** El alta reventaba con un 500 al repetir el
 * nombre de un producto que se había borrado: la comprobación los ignoraba, el
 * índice no. Y el índice tiene razón, porque el slug es parte de una URL
 * pública que puede estar compartida: reutilizarlo llevaría a quien abriera el
 * enlace de antes a un producto distinto.
 */
final class ProductSlugger
{
    /** Tope de intentos antes de rendirse y usar algo aleatorio. */
    private const MAX_ATTEMPTS = 50;

    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    public function forTitle(string $businessId, string $title): string
    {
        $base = self::slugify($title) ?: 'producto';

        if (!$this->products->slugTaken($businessId, $base)) {
            return $base;
        }

        for ($i = 2; $i <= self::MAX_ATTEMPTS; ++$i) {
            $candidato = $base.'-'.$i;
            if (!$this->products->slugTaken($businessId, $candidato)) {
                return $candidato;
            }
        }

        // Con cincuenta homónimos, numerar deja de aportar nada.
        return $base.'-'.bin2hex(random_bytes(4));
    }

    public static function slugify(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($value));

        return trim(
            preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii !== false ? $ascii : $value)) ?? '',
            '-',
        );
    }
}
