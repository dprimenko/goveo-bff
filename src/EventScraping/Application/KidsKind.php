<?php

declare(strict_types=1);

namespace App\EventScraping\Application;

/**
 * Si algo es para niños, y en qué subnivel de **Niños** (`events-kids`) cae:
 * «infantil», «familiar», una edad recomendada baja, títeres, cuentacuentos.
 *
 * Lo usan las agendas municipales, que mezclan lo infantil con plenos, talleres
 * de mayores y presentaciones de libros: de las que vienen del Excel de planes
 * para niños (14-10-2026) sólo se importa lo que esto reconoce.
 */
final class KidsKind
{
    private const KIDS = '/infantil|familiar|en familia|beb[eé]s|bebecuento|ni[nñ]os|peques|t[ií]teres|marionetas'
        . '|cuentacuentos|cuentaller|ludoteca|a partir de [1-9] a[nñ]os|de [0-9] a 1?[0-9] a[nñ]os/u';

    /** @return array{0: string, 1: string}|null */
    public static function of(string $text): ?array
    {
        $text = mb_strtolower($text);
        if (!preg_match(self::KIDS, $text)) {
            return null;
        }

        return match (true) {
            (bool) preg_match('/cuentacuentos|cuentaller|cuentos?\b/u', $text)                 => ['events-kids', 'events-kids-storytelling'],
            (bool) preg_match('/\btaller/u', $text)                                            => ['events-kids', 'events-kids-workshops'],
            (bool) preg_match('/teatro|t[ií]teres|marionetas|circo|magia|musical/u', $text)  => ['events-kids', 'events-kids-theater'],
            default                                                                             => ['events-kids', 'events-kids-family-plans'],
        };
    }
}
