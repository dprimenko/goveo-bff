<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\WebPage;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * La cartelera de **Ticketing El Corte Inglés** tal como la da el feed de
 * productos de Awin (CSV), con el enlace de afiliado: la compra lleva comisión.
 *
 * No es una web que se lea sola, sino un fichero que se descarga del panel de
 * Awin y se pasa a `goveo:events:import-awin`; por eso **no se registra** con
 * las demás fuentes (`#[Exclude]`) y la crea el comando con la ruta del CSV.
 *
 * Lo que trae el feed y cómo se lee:
 *
 * - **Toda España** (y algo de Portugal, que no entra). Cada evento va a la
 *   Agenda de su ciudad, o al negocio de la sala si ya está en Goveo.
 * - **Sólo días, sin hora** (la duración es siempre «0 minutos»): como el
 *   Calderón, cada evento ocupa sus días enteros, del próximo pase al último.
 * - **Coordenadas a veces mal** (Badajoz en latitud 29, el Paralelo en la 39):
 *   fuera lo que no cae en España o lejos del resto de su ciudad. Sin ciudad ni
 *   coordenadas tampoco entra —es «dirección que no se encuentra»—.
 * - **Tipo y subnivel por su categoría** (`TYPES`), que es la de El Corte
 *   Inglés: «Teatro › Monólogos», «En familia › Parques Temáticos»…
 * - Unos pocos traen de imagen el «missing» genérico: van sin imagen, y el
 *   comando les pone el cartel de «no disponible».
 */
#[Exclude]
final class AwinElCorteInglesSource implements EventSource
{
    public const NAME = 'elcorteingles';

    /** Lo que El Corte Inglés publica sin cartel: su imagen de relleno. */
    private const MISSING_IMAGE = '/assets/missing/';

    /** Una ciudad cuyos eventos se alejan más que esto de los demás tiene las coordenadas mal. */
    private const MAX_KM_FROM_CITY = 40.0;

    /**
     * Categoría y subcategoría de El Corte Inglés → [tipo, subnivel]. `'*'` es
     * cualquier otra subcategoría de esa categoría. Subnivel nulo en Conciertos
     * lo decide `ConcertKind` (clásica o moderna por el texto) y en Teatro y
     * escena `TheaterKind` (por la sala); `self::BIG` mira si es un recinto grande.
     *
     * Decidido con el usuario el 12-10-2026, con los tipos y subniveles nuevos
     * de `Version20261012100000`.
     */
    private const TYPES = [
        'Conciertos' => [
            'Flamenco'            => ['events-flamenco', 'events-flamenco-show'],
            'Música Clásica'      => ['events-small-concerts', 'events-small-concerts-classical'],
            'Ópera y Zarzuela'    => ['events-small-concerts', 'events-small-concerts-classical'],
            'Bandas sonoras'      => ['events-small-concerts', null],
            'Gospel'              => ['events-small-concerts', null],
            'Villancicos'         => ['events-small-concerts', null],
            'Música cofrade'      => ['events-small-concerts', null],
            'Carnaval'            => ['events-stage', 'events-stage-comedy'],
            '*'                   => ['events-small-concerts', self::BIG],
        ],
        'Festivales' => [
            'Danza'               => ['events-stage', 'events-stage-dance'],
            '*'                   => ['events-small-concerts', 'events-small-concerts-festivals'],
        ],
        'Teatro' => [
            'Monólogos'           => ['events-stage', 'events-stage-comedy'],
            'Musicales'           => ['events-stage', 'events-stage-musicals'],
            'Ballet y Danza'      => ['events-stage', 'events-stage-dance'],
            'Magia'               => ['events-stage', 'events-stage-magic'],
            'Circo y Cabaret'     => ['events-stage', 'events-stage-circus'],
            'Teatro infantil'     => ['events-kids', 'events-kids-theater'],
            'Ópera'               => ['events-small-concerts', 'events-small-concerts-classical'],
            '*'                   => ['events-stage', null],
        ],
        // Sin categoría: casi todo monólogos en teatros, algo de clásica.
        'Entrada' => [
            '*'                   => ['events-stage', null],
        ],
        'En familia' => [
            'Teatro Infantil'     => ['events-kids', 'events-kids-theater'],
            'Musicales Infantiles' => ['events-kids', 'events-kids-theater'],
            'Circo Infantil'      => ['events-kids', 'events-kids-theater'],
            'Parques Temáticos'   => ['events-kids', 'events-kids-parks'],
            '*'                   => ['events-kids', 'events-kids-family-plans'],
        ],
        'Cultura' => [
            'Exposiciones'        => ['events-art', 'events-art-temporary'],
            'Museos y Monumentos' => ['events-art', 'events-art-museums'],
            'Conferencias'        => ['events-talks', null],
            'Seminarios'          => ['events-talks', null],
            'Podcast'             => ['events-talks', null],
            'Espectáculos ecuestres' => ['events-experiences', 'events-experiences-equestrian'],
            'Turismo'             => ['events-experiences', 'events-experiences-guided-tours'],
            // Sin subcategoría son humor y magia en auditorios.
            '*'                   => ['events-stage', null],
        ],
        'Deporte' => [
            'Futbol'              => ['events-sport', 'events-sport-football'],
            'Tour Estadio'        => ['events-sport', 'events-sport-stadium-tours'],
            'Motor'               => ['events-sport', 'events-sport-motor'],
            'Deportes de contacto' => ['events-sport', 'events-sport-combat'],
            'Inscripciones Deportivas' => ['events-sport', 'events-sport-races'],
            '*'                   => ['events-sport', null],
        ],
        'Experiencias' => [
            'Gastronomía'         => ['events-experiences', 'events-experiences-gastronomy'],
            'Escape Room'         => ['events-experiences', 'events-experiences-escape'],
            'Interactiva'         => ['events-experiences', 'events-experiences-escape'],
            'Fiestas'             => ['events-nightlife', null],
            '*'                   => ['events-experiences', null],
        ],
    ];

    /** Marca de «grande o en sala según el recinto» en `TYPES`. */
    private const BIG = 'big';

    /** Recintos de conciertos grandes: estadios, pabellones, arenas, plazas de toros. */
    private const BIG_VENUES = '/\b(estadi|stadium|arena\b|wizink|palau sant jordi|palau d.esports|pabell[oó]n|'
        . 'palacio de (los )?deportes|polideportivo|plaza de toros|coliseo|metropolitano|bernab[eé]u|'
        . 'recinto ferial|iberdrola music|hip[oó]dromo|auditorio (rocío jurado|cartuja|marbella)|ifema)/iu';

    /** Ciudades que el feed da «al revés» o con su nombre en dos lenguas. */
    private const CITY_FIXES = ['/^(.+), (Las|Los|La|El|A|O)$/u' => '$2 $1', '/^(.+) \((Las|Los|La|El)\)$/u' => '$2 $1'];

    /** @var list<ScrapedEvent>|null */
    private ?array $events = null;

    /** @var array<string, int> Descartes del fichero, por motivo. */
    private array $discarded = [];

    public function __construct(
        private readonly string $csvPath,
        private readonly WebPage $web,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    /** No es de una agenda: trae toda España. Cada evento lleva su ciudad. */
    public function city(): string
    {
        return 'España';
    }

    /** @return list<ScrapedEvent> */
    public function fetch(): iterable
    {
        return $this->events ??= $this->read();
    }

    /** @return array<string, int> lo que no entró del fichero y por qué */
    public function discarded(): array
    {
        $this->fetch();

        return $this->discarded;
    }

    /**
     * El cartel de entradas.plus viene en 400×600 (`m_poster`); hay uno al
     * doble (`l_poster`), que se usa si existe.
     */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->imageUrl === null || !str_contains($event->imageUrl, '/m_poster.')) {
            return $event;
        }

        $large = str_replace('/m_poster.', '/l_poster.', $event->imageUrl);

        return $this->web->get($large, 8 * 1024 * 1024) !== null ? $event->withDetails($large, null) : $event;
    }

    /** No se crean salas: el feed no trae web ni logo con que hacer la ficha. */
    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        return null;
    }

    /** @return list<ScrapedEvent> */
    private function read(): array
    {
        $handle = @fopen($this->csvPath, 'r');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('No se puede leer %s', $this->csvPath));
        }

        $header = fgetcsv($handle, escape: '');
        if ($header === false) {
            throw new \RuntimeException('El CSV está vacío');
        }
        $header = array_map(fn ($h) => trim((string) $h, "\u{FEFF} "), $header);

        $rows = [];
        while (($line = fgetcsv($handle, escape: '')) !== false) {
            if (count($line) !== count($header)) {
                $this->discard('fila mal formada');
                continue;
            }
            $rows[] = array_combine($header, $line);
        }
        fclose($handle);

        $events = [];
        foreach ($rows as $row) {
            if (!str_contains($row['merchant_name'] ?? '', 'Corte Ingl')) {
                $this->discard('no es de El Corte Inglés');
                continue;
            }
            $event = $this->toEvent($row);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $this->dropMisplaced($events);
    }

    /** @param array<string, string> $row */
    private function toEvent(array $row): ?ScrapedEvent
    {
        // La dirección de la sala: «calle, "ciudad", "provincia", "país",».
        $address = str_getcsv(self::fixEncoding($row['Tickets:venue_address'] ?? ''), escape: '');
        $street  = trim($address[0] ?? '');
        $country = self::fixEncoding(trim($row['Tickets:event_location_country'] ?? '') ?: trim($address[3] ?? ''));
        if ($country !== 'España') {
            $this->discard('fuera de España');

            return null;
        }

        $city = self::cityName(trim($row['Tickets:event_location_city'] ?? '') ?: trim($address[1] ?? ''));
        $lat  = self::coordinate($row['Tickets:latitude'] ?? '');
        $lng  = self::coordinate($row['Tickets:longitude'] ?? '');
        if ($city === '' || $lat === null || $lng === null || !self::inSpain($lat, $lng)) {
            $this->discard('sin dirección válida');

            return null;
        }

        preg_match_all('/\d{4}-\d{2}-\d{2}/', $row['Tickets:event_date'] ?? '', $m);
        $days = array_values(array_unique($m[0]));
        sort($days);
        if ($days === []) {
            $this->discard('sin fechas');

            return null;
        }

        // Del próximo pase al último: lo pasado de un espectáculo en cartel no
        // pinta nada en el rango. Si ya pasó todo, la ventana lo descarta.
        $tz    = new \DateTimeZone('Europe/Madrid');
        $today = (new \DateTimeImmutable('today', $tz))->format('Y-m-d');
        $next  = current(array_filter($days, fn ($d) => $d >= $today)) ?: $days[0];
        $start = new \DateTimeImmutable($next . ' 00:00', $tz);
        $end   = new \DateTimeImmutable(end($days) . ' 23:59', $tz);

        $weekdays = array_values(array_unique(array_map(fn ($d) => (int) (new \DateTimeImmutable($d))->format('N'), $days)));
        sort($weekdays);

        $venue = self::text($row['Tickets:venue_name'] ?? '');
        $title = self::text($row['product_name'] ?? '');
        [$type, $subtype] = self::type($row['merchant_category'] ?? '', self::text($row['merchant_product_second_category'] ?? ''), $title, $venue);

        $image = trim($row['merchant_image_url'] ?? '');
        if ($image === '' || str_contains($image, self::MISSING_IMAGE) || !str_starts_with($image, 'http')) {
            $image = null;
        }

        return new ScrapedEvent(
            source: self::NAME,
            externalId: trim($row['merchant_product_id'] ?: $row['aw_product_id']),
            title: $title,
            start: $start,
            end: $end,
            city: $city,
            venueName: $venue,
            latitude: $lat,
            longitude: $lng,
            link: trim($row['aw_deep_link'] ?? '') ?: null,
            linkAction: 'buy',
            description: self::description($row['description'] ?? ''),
            imageUrl: $image,
            detailUrl: trim($row['merchant_deep_link'] ?? '') ?: null,
            weekdays: count($weekdays) < 7 ? $weekdays : null,
            venueAddress: $street !== '' ? mb_substr("{$street}, {$city}", 0, 255) : null,
            subcategory: $type,
            subtype: $subtype,
        );
    }

    /** @return array{0: string, 1: ?string} */
    private static function type(string $category, string $sub, string $title, string $venue): array
    {
        $types = self::TYPES[$category] ?? ['*' => ['events-other', null]];
        [$type, $subtype] = $types[$sub] ?? $types['*'];

        // El Auditorio Nacional, sin categoría, es clásica.
        if ($category === 'Entrada' && preg_match('/auditorio nacional/iu', $venue) === 1) {
            return ['events-small-concerts', 'events-small-concerts-classical'];
        }
        // El fútbol también trae las visitas al estadio.
        if ($subtype === 'events-sport-football' && preg_match('/\btour\b|museo/iu', $title) === 1) {
            return [$type, 'events-sport-stadium-tours'];
        }
        if ($subtype === self::BIG) {
            return [$type, preg_match(self::BIG_VENUES, $venue) === 1 ? 'events-small-concerts-big' : 'events-small-concerts-modern'];
        }

        return [$type, $subtype];
    }

    /**
     * Fuera los eventos que caen lejos del resto de su ciudad: con tres o más
     * en la ciudad, la mediana de sus coordenadas dice dónde está.
     *
     * @param list<ScrapedEvent> $events
     *
     * @return list<ScrapedEvent>
     */
    private function dropMisplaced(array $events): array
    {
        $byCity = [];
        foreach ($events as $e) {
            $byCity[$e->city][] = $e;
        }

        $kept = [];
        foreach ($byCity as $list) {
            if (count($list) < 3) {
                array_push($kept, ...$list);
                continue;
            }
            $lat = self::median(array_map(fn ($e) => $e->latitude, $list));
            $lng = self::median(array_map(fn ($e) => $e->longitude, $list));
            foreach ($list as $e) {
                if (self::km($lat, $lng, (float) $e->latitude, (float) $e->longitude) > self::MAX_KM_FROM_CITY) {
                    $this->discard('coordenadas lejos de su ciudad');
                    continue;
                }
                $kept[] = $e;
            }
        }

        return $kept;
    }

    private function discard(string $reason): void
    {
        $this->discarded[$reason] = ($this->discarded[$reason] ?? 0) + 1;
    }

    /** «Rozas de Madrid, Las» → «Las Rozas de Madrid»; «Alicante/Alacant» → «Alicante». */
    private static function cityName(string $city): string
    {
        $city = self::fixEncoding($city);
        $city = trim(explode('/', $city)[0]);

        return trim((string) preg_replace(array_keys(self::CITY_FIXES), array_values(self::CITY_FIXES), $city));
    }

    /** El feed las da como `2.16794""`. */
    private static function coordinate(string $value): ?float
    {
        $value = trim($value, " \"'");

        return is_numeric($value) && (float) $value !== 0.0 ? (float) $value : null;
    }

    /** Península y Baleares, Canarias, Ceuta y Melilla. */
    private static function inSpain(float $lat, float $lng): bool
    {
        return ($lat >= 35.0 && $lat <= 44.0 && $lng >= -9.5 && $lng <= 4.5)
            || ($lat >= 27.5 && $lat <= 29.5 && $lng >= -18.3 && $lng <= -13.3);
    }

    /** Parte del texto llega leído en Latin-1 dos veces («EspaÃ±a»). */
    private static function fixEncoding(string $text): string
    {
        if (preg_match('/Ã[\x{80}-\x{BF}¡-ÿ]/u', $text) !== 1) {
            return $text;
        }
        $fixed = mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');

        return mb_check_encoding($fixed, 'UTF-8') ? $fixed : $text;
    }

    private static function text(string $text): string
    {
        return trim(html_entity_decode(self::fixEncoding($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }

    /**
     * Los saltos de línea llegan como «rn» pegado (se perdió la barra de
     * `\r\n`): sólo se tocan donde no puede ser parte de una palabra.
     */
    private static function description(string $text): ?string
    {
        $text = self::text($text);
        $text = str_replace('rnrn', "\n\n", $text);
        $text = (string) preg_replace('/(?<=[.!?:;»"”)])rn(?=\s*[\p{Lu}¿¡"«(\d])/u', "\n", $text);
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));

        return $text !== '' ? $text : null;
    }

    /** @param list<float|null> $values */
    private static function median(array $values): float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));
        sort($values);
        $n = count($values);

        return $n % 2 === 1 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    private static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
