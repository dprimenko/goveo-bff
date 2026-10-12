<?php

declare(strict_types=1);

namespace App\Tests\EventScraping;

use App\EventScraping\Application\AffiliateRelinker;
use App\EventScraping\Infrastructure\Source\AwinElCorteInglesSource;
use App\EventScraping\Infrastructure\WebPage;
use PHPUnit\Framework\TestCase;

/** El CSV de Awin de El Corte Inglés, con filas como las del feed real. */
final class AwinElCorteInglesSourceTest extends TestCase
{
    private const HEADER = ['aw_deep_link', 'product_name', 'aw_product_id', 'merchant_product_id', 'merchant_image_url',
        'description', 'merchant_category', 'merchant_name', 'merchant_deep_link', 'merchant_product_second_category',
        'Tickets:event_date', 'Tickets:venue_name', 'Tickets:venue_address', 'Tickets:longitude', 'Tickets:latitude',
        'Tickets:event_location_city', 'Tickets:event_location_country'];

    public function testReadsDatesPlaceTypeAndAffiliateLink(): void
    {
        $events = $this->read([
            $this->row('Buenas Noches in The Night - Facu Díaz', 'Teatro', 'Monólogos', 'Teatro Alcázar', 'Madrid', '40.4187', '-3.6993', '2099-11-20,"2099-11-27","2099-11-21"'),
        ]);

        self::assertCount(1, $events);
        $e = $events[0];
        self::assertSame('2099-11-20 00:00', $e->start->format('Y-m-d H:i'));
        self::assertSame('2099-11-27 23:59', $e->end?->format('Y-m-d H:i'));
        self::assertSame([5, 6], $e->weekdays);
        self::assertSame(['events-stage', 'events-stage-comedy'], [$e->subcategory, $e->subtype]);
        self::assertSame('https://www.awin1.com/pclick.php?p=1', $e->link);
        self::assertSame('buy', $e->linkAction);
        self::assertSame('Calle Alcalá 20, Madrid', $e->venueAddress);
    }

    public function testFixesCityNamesAndBigVenues(): void
    {
        $events = $this->read([
            $this->row('Harry Styles', 'Conciertos', 'Pop', 'Riyadh Air Metropolitano', 'Rozas de Madrid, Las', '40.43', '-3.6', '2099-07-30'),
            $this->row('Parque', 'En familia', 'Parques Temáticos', 'Bioparc', 'Alicante/Alacant', '38.3', '-0.5', '2099-07-30'),
        ]);

        self::assertSame('Las Rozas de Madrid', $events[0]->city);
        self::assertSame('events-small-concerts-big', $events[0]->subtype);
        self::assertSame('Alicante', $events[1]->city);
        self::assertSame('events-kids-parks', $events[1]->subtype);
    }

    public function testLeavesOutAbroadAndBrokenCoordinates(): void
    {
        $events = $this->read([
            $this->row('NOS Alive', 'Festivales', 'Indie y Alternativo', 'Passeio Marítimo', 'Oeiras', '38.69', '-9.23', '2099-07-08', 'PT'),
            $this->row('Flamenco', 'Conciertos', 'Flamenco', 'Teatro López de Ayala', 'Badajoz', '29.36', '-6.97', '2099-07-08'),
        ]);

        self::assertSame([], $events);
    }

    public function testMissingPosterGoesWithoutImage(): void
    {
        $row = $this->row('Sin cartel', 'Teatro', '', 'Teatro Lara', 'Madrid', '40.42', '-3.70', '2099-07-08');
        $row[4] = '/entradas/assets/missing/product-c8d7.jpg';

        self::assertNull($this->read([$row])[0]->imageUrl);
    }

    public function testSameShowAcrossSources(): void
    {
        self::assertTrue(AffiliateRelinker::sameTitle('Clase Turista', 'Clase turista'));
        self::assertTrue(AffiliateRelinker::sameTitle('Gala de Ópera', 'Filarmonía de Madrid. Gala de Ópera'));
        self::assertTrue(AffiliateRelinker::sameTitle('Wicked, El Musical', 'WICKED, EL MUSICAL'));
        self::assertFalse(AffiliateRelinker::sameTitle('Carmen', 'Carmen Linares en concierto'));
        self::assertFalse(AffiliateRelinker::sameTitle('El Rey León', 'La Familia Addams, el musical'));
    }

    /** @param list<list<string>> $rows */
    private function read(array $rows): array
    {
        $path = tempnam(sys_get_temp_dir(), 'awin');
        $fh   = fopen($path, 'w');
        fputcsv($fh, self::HEADER, escape: '');
        foreach ($rows as $row) {
            fputcsv($fh, $row, escape: '');
        }
        fclose($fh);

        $web = (new \ReflectionClass(WebPage::class))->newInstanceWithoutConstructor();

        try {
            return (new AwinElCorteInglesSource($path, $web))->fetch();
        } finally {
            unlink($path);
        }
    }

    /** @return list<string> */
    private function row(string $title, string $category, string $sub, string $venue, string $city, string $lat, string $lng, string $dates, string $country = 'EspaÃ±a'): array
    {
        return ['https://www.awin1.com/pclick.php?p=1', $title, '1', 'performance-' . md5($title . $city), 'https://example.com/poster.jpg',
            'Primera parte.rnrnSegunda parte.', $category, 'Ticketing El Corte Inglés ES (P)', 'https://www.elcorteingles.es/entradas/x/', $sub,
            $dates, $venue, 'Calle Alcalá 20,"' . $city . '","' . $city . '","' . $country . '",', $lng . '""', $lat . '""', $city, $country];
    }
}
