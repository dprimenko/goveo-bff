<?php

declare(strict_types=1);

namespace App\EventScraping\Application;

/**
 * Música clásica o moderna, para el subnivel de Conciertos.
 *
 * Unas treinta fuentes dan conciertos sin decir de qué, así que lo decide el
 * importador mirando el texto, en vez de repetir la misma regla en cada una.
 * Se buscan las señas de la clásica —que son pocas y claras: orquesta,
 * sinfónico, cámara, coro, ópera, un compositor— y todo lo demás es moderna,
 * que es lo que dan las salas.
 */
final class ConcertKind
{
    public const CLASSICAL = 'events-small-concerts-classical';
    public const MODERN    = 'events-small-concerts-modern';

    private const CLASSICAL_SIGNS = '/\b(orquesta|sinf[oó]nic|filarm[oó]nic|c[aá]mara|cuarteto de cuerda|'
        . 'coral\b|coro\b|[oó]pera\b|zarzuela|l[ií]ric[oa]|barroc|cl[aá]sic[oa]|recital|piano solo|organista|[oó]rgano\b|'
        . 'bach|mozart|beethoven|vivaldi|brahms|schubert|chopin|haydn|h[aä]ndel|mahler|tchaikovsk|chaikovsk|'
        . 'debussy|ravel|falla|albéniz|granados|rodrigo|ocne|ornamente|scherzo|ibermúsica|juventudes musicales|'
        . 'auditorio nacional)/iu';

    /** El subnivel según el título, la descripción y la sala. */
    public static function of(string $text): string
    {
        return preg_match(self::CLASSICAL_SIGNS, $text) === 1 ? self::CLASSICAL : self::MODERN;
    }
}
