<?php

declare(strict_types=1);

namespace App\EventScraping\Application;

/**
 * En qué tipo de teatro cae una obra: **Grandes teatros**, **Salas de teatro**
 * o **Teatros en centros culturales** (lo que era Escena, `events-stage`).
 *
 * Lo pidió negocio el 05-10-2026: «Escena» mezclaba la Gran Vía con la casa de
 * cultura de un pueblo. Las fuentes siguen diciendo `events-stage` y su
 * subnivel (teatro, musical, humor…); esto lo recoloca por **dónde** es, para no
 * repetir la regla en cada una:
 *
 * - Una fuente de un solo teatro, o de teatros de la misma clase, va entera
 *   (`BIG_SOURCES`, `HALL_SOURCES`).
 * - Las agendas generales (Ayuntamiento, esMadrid, Comunidad) mezclan de todo:
 *   se mira el nombre de la sala.
 * - Lo demás se queda en centros culturales.
 *
 * Grandes teatros y Salas no tienen subniveles: el subnivel se pierde al
 * moverlo. «Teatro» ya no es subnivel de nada (era el cajón por defecto).
 */
final class TheaterKind
{
    public const BIG      = 'events-big-theaters';
    public const HALLS    = 'events-theater-halls';
    public const CULTURAL = 'events-stage';

    /** Gran Vía y teatros comerciales estables, y los públicos importantes. */
    private const BIG_SOURCES = [
        'gruposmedia', 'stage', 'atg', 'calderon', 'grupo-marquina', 'teatro-la-latina', 'teatro-pavon',
        'infanta-isabel', 'teatro-lara', 'teatro-real', 'teatro-zarzuela', 'teatros-canal', 'cntc', 'teatro-abadia',
    ];

    /** Salas pequeñas y alternativas. */
    private const HALL_SOURCES = ['microteatro', 'teseo-teatro', 'corral-alcala'];

    /** Fuentes que mezclan salas de todo tipo: se decide por el nombre de la sala. */
    private const AGENDAS = ['madrid-datos', 'esmadrid', 'comunidad-madrid'];

    /** Teatros públicos importantes que llegan por las agendas. */
    private const BIG_VENUES = '/teatro espa[nñ]ol|naves del espa[nñ]ol|fern[aá]n g[oó]mez|teatro real|zarzuela|'
        . 'teatros del canal|mar[ií]a guerrero|valle[- ]incl[aá]n|teatro de la comedia|teatro de la abad[ií]a|'
        . 'gran v[ií]a|teatro calder[oó]n|teatro lope de vega|coliseum|nuevo teatro alcal[aá]|teatro apolo|'
        . 'teatro rialto|teatro amaya|teatro infanta isabel|teatro la latina|teatro marquina|teatro lara|'
        . 'teatro pav[oó]n|teatro bellas artes|teatro reina victoria|teatro maravillas|teatro alc[aá]zar|'
        . 'teatro f[ií]garo|teatro cofidis|teatro edp|teatro capitol|teatro arlequ[ií]n|'
        . 'teatro muñoz seca|teatro victoria|teatro galileo|teatro pr[ií]ncipe/iu';

    /** Lo que es de un centro cultural, una biblioteca o un ayuntamiento. */
    private const CULTURAL_VENUES = '/centro cultural|centro sociocultural|centro municipal|casa de (la )?cultura|'
        . 'biblioteca|junta municipal|auditorio municipal|espacio municipal|centro c[ií]vico|matadero|conde duque|'
        . 'centrocentro|quinta de los molinos/iu';

    /**
     * El tipo y el subnivel definitivos de algo que la fuente da como Escena.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function of(string $source, string $venue, ?string $subtype): array
    {
        $cultural = [self::CULTURAL, $subtype === 'events-stage-theater' ? null : $subtype];

        if (in_array($source, self::BIG_SOURCES, true)) {
            return [self::BIG, null];
        }
        if (in_array($source, self::HALL_SOURCES, true)) {
            return [self::HALLS, null];
        }
        if (!in_array($source, self::AGENDAS, true)) {
            return $cultural;
        }

        // Primero los grandes por su nombre: las Naves del Español están en
        // Matadero, que si no caería en centros culturales.
        return match (true) {
            preg_match(self::BIG_VENUES, $venue) === 1      => [self::BIG, null],
            preg_match(self::CULTURAL_VENUES, $venue) === 1 => $cultural,
            // En esMadrid y la Comunidad, el resto son salas privadas: un
            // «Teatro X» comercial es de los grandes, una «Sala» o un
            // «Espacio», de las pequeñas. El Ayuntamiento sólo trae lo suyo.
            $source === 'madrid-datos'                      => $cultural,
            preg_match('/^\s*teatro\b/iu', $venue) === 1    => [self::BIG, null],
            default                                         => [self::HALLS, null],
        };
    }
}
