<?php

declare(strict_types=1);

namespace App\Business\Application;

use App\Business\Domain\BusinessRepository;

/**
 * Genera el slug de un negocio a partir de su nombre, garantizando que es único.
 *
 * `business.slug` tiene índice único y además es una ruta pública
 * (`/public/businesses/{slug}`), así que dos «Bar Manolo» no pueden compartirlo:
 * al segundo se le añade un sufijo.
 */
final class BusinessSlugger
{
    private const MAX_LENGTH = 200;
    /** Tope de intentos antes de rendirse y usar algo aleatorio. */
    private const MAX_ATTEMPTS = 50;

    public function __construct(
        private readonly BusinessRepository $businesses,
    ) {}

    public function forName(string $name): string
    {
        $base = self::slugify($name);

        if ($base === '') {
            $base = 'negocio';
        }

        if ($this->businesses->findBySlug($base) === null) {
            return $base;
        }

        for ($i = 2; $i <= self::MAX_ATTEMPTS; ++$i) {
            $candidate = $base.'-'.$i;
            if ($this->businesses->findBySlug($candidate) === null) {
                return $candidate;
            }
        }

        // Con 50 homónimos, numerar deja de aportar nada.
        return $base.'-'.bin2hex(random_bytes(4));
    }

    public static function slugify(string $value): string
    {
        $value = trim($value);

        // Quita tildes y eñes conservando la letra: «Jamonería» → «jamoneria».
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false) {
            $value = $ascii;
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return substr($value, 0, self::MAX_LENGTH);
    }
}
