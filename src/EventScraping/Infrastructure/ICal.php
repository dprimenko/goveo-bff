<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure;

/**
 * Lectura mínima de calendarios iCal: lo justo para una agenda, sin librería.
 *
 * Siroco, Sala El Sol y Fundación Telefónica llevan cada una su copia de esto,
 * de antes de que hubiera más; las agendas municipales usan ésta.
 */
final class ICal
{
    /**
     * Los `VEVENT` del fichero como `propiedad => valor`, sin parámetros
     * (`DTSTART;TZID=…` → `DTSTART`) y con las líneas plegadas ya unidas.
     *
     * @return list<array<string, string>>
     */
    public static function events(string $ics): array
    {
        $ics    = (string) preg_replace('/\r?\n[ \t]/', '', $ics);
        $events = [];
        foreach (explode('BEGIN:VEVENT', $ics) as $i => $block) {
            if ($i === 0) {
                continue;
            }
            $props = [];
            foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
                if (preg_match('/^([A-Z-]+)[^:]*:(.*)$/', $line, $m) && !isset($props[$m[1]])) {
                    $props[$m[1]] = str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], trim($m[2]));
                }
            }
            $events[] = $props;
        }

        return $events;
    }

    /**
     * `20260925T210000` en hora de Madrid, o `20260925` (el día entero, a las
     * 00:00). La `Z` final se ignora: Boadilla la pone junto a `TZID=Europe/Madrid`
     * y la hora que escribe es la local («18:00 h» en la ficha, `180000Z`).
     */
    public static function date(?string $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if ($raw === null) {
            return null;
        }
        if (preg_match('/^(\d{8}T\d{6})Z?$/', $raw, $m)) {
            return \DateTimeImmutable::createFromFormat('Ymd\THis', $m[1], $tz) ?: null;
        }
        if (preg_match('/^\d{8}$/', $raw)) {
            return \DateTimeImmutable::createFromFormat('!Ymd', $raw, $tz) ?: null;
        }

        return null;
    }
}
