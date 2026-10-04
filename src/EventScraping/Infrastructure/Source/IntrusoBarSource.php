<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Application\Shows;
use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Intruso Bar (intrusobar.com), club de conciertos en Chueca: jazz, soul,
 * funk, jams cada noche y algo de comedia y poesía.
 *
 * La web es una aplicación de Angular que pide la agenda a su propio backend
 * (`/backend/buscaEventos.php`): un JSON con **todo** lo que ha programado
 * desde 2021 (~2,5 MB), con fecha, hora, cartel, texto y enlace de entradas.
 * Una petición; lo pasado se queda fuera aquí.
 *
 * - El cartel es `assets/imagenes/470/<URL_FOTO>`, que es lo que pinta la web.
 * - Las jams semanales («INTRUSO ACID JAM!» los martes) son un evento con su
 *   rango por el nombre (ver `Shows`), como en Tempo o La Palma.
 * - Las entradas anticipadas se venden en Giglon: si las hay, ése es el botón.
 */
final class IntrusoBarSource implements EventSource
{
    private const SITE    = 'https://www.intrusobar.com';
    private const API     = self::SITE . '/backend/buscaEventos.php';
    private const IMAGES  = self::SITE . '/assets/imagenes/470/';
    private const NAME    = 'Intruso Bar';
    private const LAT     = 40.4231123;
    private const LNG     = -3.7002265;
    private const ADDRESS = 'Calle de Augusto Figueroa, 3, 28004 Madrid';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'intruso-bar';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        // El histórico entero: el tope por defecto de 5 MB se quedaría corto en
        // unos años.
        $json = $this->web->get(self::API, 30 * 1024 * 1024);
        $list = $json === null ? null : json_decode($json, true);
        if (!is_array($list)) {
            throw new \RuntimeException('No se pudo descargar la agenda del Intruso');
        }

        $tz     = new \DateTimeZone('Europe/Madrid');
        $today  = (new \DateTimeImmutable('today', $tz))->format('Y-m-d');
        $passes = [];

        foreach ($list as $e) {
            $date  = (string) ($e['FECHA'] ?? '');
            $title = Html::clean((string) ($e['NOMBRE'] ?? ''), 200);
            $photo = trim((string) ($e['URL_FOTO'] ?? ''));
            if ($date < $today || $title === null || $photo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }

            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
            if ($start === false) {
                continue;
            }
            if (preg_match('/^(\d{1,2}):(\d{2})/', (string) ($e['HORA'] ?? ''), $t)) {
                $start = $start->setTime((int) $t[1], (int) $t[2]);
            }

            $tickets = trim((string) ($e['URL_ENTRADA_ANT'] ?? ''));
            $tickets = $tickets === '' ? null : (preg_match('#^https?://#', $tickets) ? $tickets : 'https://' . $tickets);
            $detail  = sprintf('%s/#/evento/%s/%s/%s', self::SITE, (string) ($e['indice'] ?? ''), $date, rawurlencode(str_replace(' ', '-', (string) $e['NOMBRE'])));
            [$subcategory, $subtype] = $this->classify(mb_strtolower($title . ' ' . ($e['ESTILO'] ?? '')));

            $passes[] = new ScrapedEvent(
                source: $this->name(),
                externalId: $this->slug($title),
                title: $title,
                start: $start,
                end: $start->format('H:i') === '00:00' ? $start->setTime(23, 59) : null,
                city: $this->city(),
                venueName: self::NAME,
                latitude: self::LAT,
                longitude: self::LNG,
                link: $tickets ?? $detail,
                linkAction: $tickets !== null ? 'buy' : 'info',
                description: Html::clean((string) ($e['TEXTO_ES'] ?? '')),
                imageUrl: self::IMAGES . rawurlencode($photo),
                detailUrl: $detail,
                venueAddress: self::ADDRESS,
                subcategory: $subcategory,
                subtype: $subtype,
            );
        }

        return Shows::group($passes);
    }

    /** El JSON ya lo trae todo. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: self::NAME,
            city: $this->city(),
            categorySlug: 'live-music',
            latitude: self::LAT,
            longitude: self::LNG,
            address: self::ADDRESS,
            website: self::SITE . '/',
        );
    }

    /** @return array{0: string, 1: ?string} */
    private function classify(string $text): array
    {
        return match (true) {
            (bool) preg_match('/comedy|comedia|mon[oó]logo|stand.?up/u', $text) => ['events-stage', 'events-stage-comedy'],
            (bool) preg_match('/\bdj\b|dj set|sesi[oó]n dj/u', $text)          => ['events-nightlife', 'events-nightlife-dj-sessions'],
            (bool) preg_match('/poetry|poes[ií]a/u', $text)                     => ['events-other', null],
            default                                                            => ['events-small-concerts', null],
        };
    }

    private function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return mb_substr(trim(preg_replace('/[^a-z0-9]+/', '-', $text) ?? '', '-'), 0, 200);
    }
}
