<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Galería Elvira González (elviragonzalez.es), arte contemporáneo en Chamberí.
 *
 * Una exposición cada vez, en «Exposición actual»: título, artista, fechas
 * («10.09/24.10 2026», el año sólo al final) y fotos de sala. Las próximas no
 * se anuncian, así que la pasada siguiente a cada cambio de exposición la trae.
 * Galería de entrada libre: el enlace es la ficha.
 *
 * ⚠️ La web lleva enlaces de casinos inyectados en el pie (la han hackeado): no
 * afecta a lo que se lee, pero si cambian la maqueta al limpiarla, revisar.
 */
final class ElviraGonzalezSource implements EventSource
{
    private const SITE    = 'https://elviragonzalez.es';
    private const CURRENT = self::SITE . '/exposicion-actual/';
    private const NAME    = 'Galería Elvira González';
    private const LAT     = 40.4272699;
    private const LNG     = -3.6976072;
    private const ADDRESS = 'Calle de los Hermanos Álvarez Quintero, 1, 28004 Madrid';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'elvira-gonzalez';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::CURRENT);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la exposición de la Galería Elvira González');
        }

        $xp     = Html::xpath($html);
        $header = '//h1[' . Html::hasClass('entry-title') . ']';
        $title  = Html::clean(Html::text($xp, $header . '/span[' . Html::hasClass('titulo') . ']'), 150);
        $artist = Html::clean(Html::text($xp, $header . '/span[' . Html::hasClass('artista') . ']'), 100);
        $range  = $this->range(Html::text($xp, $header . '/span[' . Html::hasClass('fecha') . ']'));
        $image  = Html::attr($xp, '//div[@id="obrasSala"]//img', 'src');
        if ($title === null || $range === null || $image === null) {
            return [];
        }

        // El título es el de la muestra («APENAS TRANSITAR»): con el artista
        // delante se sabe de quién es.
        $full = $artist !== null && $artist !== $title ? $artist . ': ' . $title : $title;

        return [new ScrapedEvent(
            source: $this->name(),
            // La fecha de inicio distingue una exposición de la siguiente: la
            // página es siempre la misma.
            externalId: $range[0]->format('Y-m-d') . '-' . $this->slug($full),
            title: $full,
            start: $range[0],
            end: $range[1],
            city: $this->city(),
            venueName: self::NAME,
            latitude: self::LAT,
            longitude: self::LNG,
            link: self::CURRENT,
            description: Html::clean(Html::text($xp, '//div[' . Html::hasClass('entry-content') . ']')),
            imageUrl: Html::absolute($image, self::SITE),
            detailUrl: self::CURRENT,
            venueAddress: self::ADDRESS,
            subcategory: 'events-art',
            subtype: 'events-art-galleries',
        )];
    }

    /** La página ya lo trae todo. */
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
            categorySlug: 'culture-shows',
            latitude: self::LAT,
            longitude: self::LNG,
            address: self::ADDRESS,
            website: self::SITE . '/',
        );
    }

    /**
     * «10.09/24.10 2026»: día.mes del inicio y del fin, y el año del fin. Si el
     * fin es de un mes anterior al inicio, la exposición empezó el año antes
     * («20.11/15.01 2027»).
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    private function range(?string $text): ?array
    {
        if ($text === null || !preg_match('#(\d{1,2})\.(\d{1,2})\s*/\s*(\d{1,2})\.(\d{1,2})\s+(\d{4})#', $text, $m)) {
            return null;
        }

        [, $d1, $m1, $d2, $m2, $year] = array_map('intval', $m);
        $startYear = $m1 > $m2 ? $year - 1 : $year;
        if (!checkdate($m1, $d1, $startYear) || !checkdate($m2, $d2, $year)) {
            return null;
        }

        $tz = new \DateTimeZone('Europe/Madrid');

        return [
            (new \DateTimeImmutable('now', $tz))->setDate($startYear, $m1, $d1)->setTime(0, 0),
            (new \DateTimeImmutable('now', $tz))->setDate($year, $m2, $d2)->setTime(23, 59),
        ];
    }

    private function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return mb_substr(trim(preg_replace('/[^a-z0-9]+/', '-', $text) ?? '', '-'), 0, 180);
    }
}
