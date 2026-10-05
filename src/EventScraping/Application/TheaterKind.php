<?php

declare(strict_types=1);

namespace App\EventScraping\Application;

/**
 * El subnivel de **Teatro y escena** (`events-stage`): el género si se sabe
 * —Musicales, Humor y monólogos, Danza, Magia, Microteatro, Circo— y, si no,
 * dónde es: **Teatro en grandes salas**, **Teatro en salas** o **Teatro en
 * centros culturales**.
 *
 * Así lo dejó negocio el 05-10-2026, tras probar a partirlo en tres tipos: el
 * tipo de sala es un subnivel más. Un evento sólo tiene un subnivel, y **gana
 * el género** porque es por lo que se busca —un musical de la Gran Vía sale en
 * «Musicales», no en «grandes salas»—.
 *
 * Las fuentes siguen diciendo `events-stage` (o `events-circus`) y su subnivel;
 * esto lo recoloca para no repetir la regla en cada una:
 *
 * - Una fuente de un solo teatro, o de teatros de la misma clase, va entera
 *   (`BIG_SOURCES`, `HALL_SOURCES`).
 * - Las agendas generales (Ayuntamiento, esMadrid, Comunidad) mezclan de todo:
 *   se mira el nombre de la sala.
 * - Lo demás, centros culturales.
 */
final class TheaterKind
{
    public const STAGE    = 'events-stage';
    public const BIG      = 'events-stage-big-venues';
    public const HALLS    = 'events-stage-halls';
    public const CULTURAL = 'events-stage-cultural';
    public const CIRCUS   = 'events-stage-circus';

    /** Los subniveles que son un género: mandan sobre la sala. */
    private const GENRES = [
        'events-stage-musicals', 'events-stage-comedy', 'events-stage-dance', 'events-stage-magic',
        'events-stage-microtheater', self::CIRCUS,
    ];

    /** Gran Vía y teatros comerciales estables, y los públicos importantes. */
    private const BIG_SOURCES = [
        'gruposmedia', 'stage', 'atg', 'calderon', 'grupo-marquina', 'teatro-la-latina', 'teatro-pavon',
        'infanta-isabel', 'teatro-lara', 'teatro-real', 'teatro-zarzuela', 'teatros-canal', 'cntc', 'teatro-abadia',
    ];

    /** Salas pequeñas y alternativas. */
    private const HALL_SOURCES = ['microteatro', 'teseo-teatro', 'corral-alcala'];

    /** Fuentes que mezclan salas de todo tipo: se decide por el nombre de la sala. */
    private const AGENDAS = ['madrid-datos', 'esmadrid', 'comunidad-madrid'];

    /** Teatros públicos importantes y comerciales que llegan por las agendas. */
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
     * El subnivel de algo que la fuente da como Escena (o como Circo).
     *
     * @param string  $type    lo que dijo la fuente: `events-stage` o `events-circus`
     * @param ?string $subtype el subnivel que dijo, si alguno
     *
     * @return array{0: string, 1: string} siempre Teatro y escena, con subnivel
     */
    public static function of(string $source, string $venue, string $type, ?string $subtype): array
    {
        if ($type === 'events-circus') {
            return [self::STAGE, self::CIRCUS];
        }
        if ($subtype !== null && in_array($subtype, self::GENRES, true)) {
            return [self::STAGE, $subtype];
        }

        return [self::STAGE, self::venueKind($source, $venue)];
    }

    private static function venueKind(string $source, string $venue): string
    {
        if (in_array($source, self::BIG_SOURCES, true)) {
            return self::BIG;
        }
        if (in_array($source, self::HALL_SOURCES, true)) {
            return self::HALLS;
        }
        if (!in_array($source, self::AGENDAS, true)) {
            return self::CULTURAL;
        }

        // Primero los grandes por su nombre: las Naves del Español están en
        // Matadero, que si no caería en centros culturales.
        return match (true) {
            preg_match(self::BIG_VENUES, $venue) === 1      => self::BIG,
            preg_match(self::CULTURAL_VENUES, $venue) === 1 => self::CULTURAL,
            // En esMadrid y la Comunidad, el resto son salas privadas: un
            // «Teatro X» comercial es de los grandes, una «Sala» o un
            // «Espacio», de las pequeñas. El Ayuntamiento sólo trae lo suyo.
            $source === 'madrid-datos'                      => self::CULTURAL,
            preg_match('/^\s*teatro\b/iu', $venue) === 1    => self::BIG,
            default                                         => self::HALLS,
        };
    }
}
