<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Application\Shows;
use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\JsonLd;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Los centros culturales propios de la Comunidad de Madrid (comunidad.madrid/centros):
 * Paco Rabal y Pilar Miró en Vallecas, el Real Coliseo de Carlos III en San
 * Lorenzo de El Escorial, la Sala Alcalá 31, la Sala de Arte Joven y el Centro
 * Comarcal de Humanidades Sierra Norte en La Cabrera.
 *
 * `comunidad-madrid` no los trae: filtra el buscador por «municipio Madrid», y
 * estos centros salen **sin municipio** (los de Vallecas) o en el suyo (El
 * Escorial, La Cabrera). Tampoco `madrid-datos`, porque no son del Ayuntamiento.
 *
 * Qué actividades son de cada centro lo dice la página del centro, que las
 * enlaza todas; los pases salen del mismo JSON del mapa que usa
 * `comunidad-madrid` (una petición, filtrada por los tipos de ocio: así se
 * quedan fuera talleres de adultos, clubes de lectura y bibliotecas), y lo que
 * ya trae `comunidad-madrid` (municipio Madrid) se resta para no duplicarlo.
 * La ficha —cartel, texto, entradas— sólo se abre para lo que pasa el filtro
 * de fechas (`enrich`): el sitio pide 10 s entre páginas en su `robots.txt`.
 *
 * Los centros son pocos y fijos: dirección y coordenadas van aquí, y cada uno
 * se da de alta como sala (`venueFor`). Los actos de Sierra Norte no traen
 * coordenadas en el mapa; con el centro fijo no hacen falta.
 */
final class ComunidadMadridCentrosSource implements EventSource
{
    private const MAP  = 'https://www.comunidad.madrid/api/events-map';
    private const HOME = 'https://www.comunidad.madrid';

    /**
     * Página del centro => [nombre, municipio, dirección, lat, lng, categoría].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: float, 4: float, 5: string}>
     */
    private const CENTERS = [
        '/centros/centro-cultural-paco-rabal' => [
            'Centro Cultural Paco Rabal', 'Madrid', 'C. de Felipe de Diego, 11, 28018 Madrid', 40.3794867, -3.661135, 'culture-shows',
        ],
        '/centros/centro-cultural-pilar-miro' => [
            'Centro Cultural Pilar Miró', 'Madrid', 'Pl. Antonio María Segovia, s/n, 28031 Madrid', 40.3810792, -3.6134868, 'culture-shows',
        ],
        '/centros/real-coliseo-carlos-iii-san-lorenzo-escorial' => [
            'Real Coliseo de Carlos III', 'San Lorenzo de El Escorial', 'C/ Floridablanca, 20, 28200 San Lorenzo de El Escorial', 40.5908936, -4.1471689, 'culture-shows',
        ],
        '/centros/museo-picasso-coleccion-eugenio-arias' => [
            'Museo Picasso Colección Eugenio Arias', 'Buitrago del Lozoya', 'Pl. de Picasso, 1, 28730 Buitrago del Lozoya', 40.993439, -3.6363194, 'tourism-museums',
        ],
        '/centros/sala-alcala-31' => [
            'Sala Alcalá 31', 'Madrid', 'C. de Alcalá, 31, 28014 Madrid', 40.4184723, -3.6982279, 'tourism-museums',
        ],
        '/centros/sala-arte-joven' => [
            'Sala de Arte Joven', 'Madrid', 'Av. de América, 13, 28002 Madrid', 40.4386118, -3.6763148, 'tourism-museums',
        ],
        '/centros/centro-comarcal-humanidades-sierra-norte' => [
            'Centro Comarcal de Humanidades Sierra Norte', 'La Cabrera', 'Av. de La Cabrera, 96, 28751 La Cabrera', 40.8730173, -3.6059804, 'culture-shows',
        ],
    ];

    /**
     * Los mismos tipos que `comunidad-madrid`: tipo del buscador → tipo y
     * subnivel de Goveo.
     *
     * @var array<string, array{0: ?string, 1: ?string}>
     */
    private const TYPES = [
        'Teatro'           => ['events-stage', 'events-stage-theater'],
        'Música'           => ['events-small-concerts', null],
        'Espectáculo'      => ['events-stage', null],
        'Danza/Baile'      => ['events-stage', 'events-stage-dance'],
        'Comedia/Humor'    => ['events-stage', 'events-stage-comedy'],
        'Exposición/Museo' => ['events-art', 'events-art-temporary'],
        'Cine/Vídeo'       => ['events-cinema', null],
        'Mercado/Feria'    => ['events-markets', 'events-markets-fairs'],
        'Festival'         => [null, null],
    ];

    /** Visitas de colegios: no son para el público. */
    private const SCHOOL = '/programa escolar|visitas? dinamizadas?|campa[nñ]a escolar|\bconvocatoria\b/iu';

    /**
     * Los talleres de estos centros son cursos de todo el trimestre (swing,
     * clown, cerámica): sólo entran los de niños.
     */
    private const COURSE = '/^taller\b/iu';

    private const KIDS = '/infantil|p[uú]blico familiar|en familia|para (ni[nñ]os|peques|toda la familia)|t[ií]teres|marionetas|cuentacuentos/u';

    /** Más que esto abierto no es una exposición: es la colección del centro. */
    private const MAX_RANGE_DAYS = 180;

    private const LOOKAHEAD_DAYS = 65;

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'comunidad-madrid-centros';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $all = $this->map([]);
        // Lo que tiene municipio Madrid ya lo lee `comunidad-madrid`.
        $covered = array_flip(array_keys($this->map(['municipio:Madrid'])));

        $tz      = new \DateTimeZone('Europe/Madrid');
        $today   = new \DateTimeImmutable('today', $tz);
        $horizon = $today->modify(sprintf('+%d days', self::LOOKAHEAD_DAYS));
        $passes  = [];

        foreach (self::CENTERS as $page => $center) {
            $html = $this->web->get(self::HOME . $page);
            if ($html === null) {
                continue;
            }

            $paths = [];
            foreach (Html::xpath($html)->query('//a[starts-with(@href, "/actividades/")]') as $a) {
                if ($a instanceof \DOMElement) {
                    $paths[strtok($a->getAttribute('href'), '?#')] = true;
                }
            }

            foreach (array_keys($paths) as $path) {
                $doc = $all[$path] ?? null;
                if ($doc === null || isset($covered[$path])) {
                    continue;
                }
                $title = $this->title((string) ($doc['ss_title'] ?? ''));
                $dates = $this->dates($doc['dm_recurring_dates'] ?? [], $tz);
                if ($title === null || $dates === [] || preg_match(self::SCHOOL, $title . ' ' . $path)
                    || (preg_match(self::COURSE, $title) && !preg_match(self::KIDS, mb_strtolower($title)))) {
                    continue;
                }
                $coming = array_values(array_filter($dates, fn ($d) => $d >= $today));
                $until  = null;
                // Una exposición sale en el mapa con su primer día: si ya pasó,
                // puede seguir abierta. Hasta cuándo lo dice la ficha, que sólo
                // se abre para esto (pocas).
                if ($coming === []) {
                    $until = $this->until(self::HOME . $path);
                    if ($until === null || $until < $today || $until->diff(end($dates))->days > self::MAX_RANGE_DAYS) {
                        continue;
                    }
                    $coming = [end($dates)];
                }
                if ($coming[0] > $horizon) {
                    continue;
                }

                array_push($passes, ...$this->performances($path, $title, $coming, $center, $until));
            }
        }

        return Shows::group($passes);
    }

    /**
     * La ficha: cartel, texto, entradas y, en las exposiciones, hasta cuándo
     * duran. Con el texto se afina si es para niños.
     */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        $html = $event->detailUrl !== null ? $this->web->get($event->detailUrl) : null;
        if ($html === null) {
            return $event;
        }

        $xp   = Html::xpath($html);
        $node = JsonLd::events($html)[0] ?? [];
        // El `og:image` es 16:9; el de los datos, una franja 3:1 que en el
        // cartel vertical se queda en nada.
        $image       = Html::absolute(Html::attr($xp, '//meta[@property="og:image"]', 'content') ?? JsonLd::text($node['image'] ?? null), self::HOME);
        $description = Html::clean(JsonLd::text($node['description'] ?? null, 'text'), 400);
        $tickets     = Html::attr($xp, '//a[starts-with(@aria-label, "Entradas")]', 'href');

        $end = $event->end;
        if (($fin = $this->date($node['endDate'] ?? null)) !== null && $fin->format('Y-m-d') > $event->start->format('Y-m-d')) {
            $end = $fin->format('H:i') === '00:00' ? $fin->setTime(23, 59) : $fin;
        }
        // Una exposición que empezó antes de hoy: la del mapa era su primer
        // día, y sin fin en la ficha no se sabe si sigue.
        if ($end === null && $event->start < new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'))) {
            return $event;
        }

        [$subcategory, $subtype] = $this->kids($event->title . ' ' . $description) ?? [$event->subcategory, $event->subtype];

        return new ScrapedEvent(
            source: $event->source,
            externalId: $event->externalId,
            title: $event->title,
            start: $event->start,
            end: $end,
            city: $event->city,
            venueName: $event->venueName,
            latitude: $event->latitude,
            longitude: $event->longitude,
            link: $tickets ?? $event->link,
            linkAction: $tickets !== null ? 'buy' : 'info',
            description: $description,
            imageUrl: $image,
            detailUrl: $event->detailUrl,
            weekdays: $event->weekdays,
            venueAddress: $event->venueAddress,
            subcategory: $subcategory,
            subtype: $subtype,
        );
    }

    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        foreach (self::CENTERS as $page => [$name, $city, $address, $lat, $lng, $category]) {
            if ($name === $event->venueName) {
                return new ScrapedVenue(
                    source: $this->name(),
                    externalId: 'venue-' . basename($page),
                    name: $name,
                    city: $city,
                    categorySlug: $category,
                    latitude: $lat,
                    longitude: $lng,
                    address: $address,
                    website: self::HOME . $page,
                );
            }
        }

        return null;
    }

    /**
     * Los pases de una actividad en su centro.
     *
     * @param list<\DateTimeImmutable>                                              $dates
     * @param array{0: string, 1: string, 2: string, 3: float, 4: float, 5: string} $center
     *
     * @return list<ScrapedEvent>
     */
    private function performances(string $path, string $title, array $dates, array $center, ?\DateTimeImmutable $until = null): array
    {
        [$name, $city, $address, $lat, $lng] = $center;
        $url = self::HOME . $path;
        [$subcategory, $subtype] = $this->kids($title) ?? $this->classify($path, $title);

        $out = [];
        foreach ($dates as $start) {
            $out[] = new ScrapedEvent(
                source: $this->name(),
                // El mismo acto publicado dos veces sale con «-0», «-1» al final.
                externalId: (string) preg_replace('/-\d{1,2}$/', '', trim($path, '/')),
                title: $title,
                start: $start,
                end: $until ?? ($start->format('H:i') === '00:00' ? $start->setTime(23, 59) : null),
                city: $city,
                venueName: $name,
                latitude: $lat,
                longitude: $lng,
                link: $url,
                detailUrl: $url,
                venueAddress: $address,
                subcategory: $subcategory,
                subtype: $subtype,
            );
        }

        return $out;
    }

    /** El fin que da la ficha (las exposiciones), hasta la noche de ese día. */
    private function until(string $url): ?\DateTimeImmutable
    {
        $html = $this->web->get($url);
        $end  = $html !== null ? $this->date(JsonLd::events($html)[0]['endDate'] ?? null) : null;

        return $end?->setTime(23, 59);
    }

    /**
     * El JSON del mapa, por ruta de la ficha: los tipos de ocio y, si se
     * piden, más filtros.
     *
     * @param list<string> $filters
     *
     * @return array<string, array<string, mixed>>
     */
    private function map(array $filters): array
    {
        $query = [];
        foreach ([...$filters, ...array_map(fn ($t) => 'tipo_actividad:' . $t, array_keys(self::TYPES))] as $i => $f) {
            $query[] = sprintf('f[%d]=%s', $i, rawurlencode($f));
        }

        $raw  = $this->web->get(self::MAP . '?' . implode('&', $query), 20 * 1024 * 1024);
        $data = $raw !== null ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_array($data['docs'] ?? null)) {
            throw new \RuntimeException('No se pudo leer el mapa de actividades de comunidad.madrid');
        }

        $out = [];
        foreach ($data['docs'] as $doc) {
            if (is_string($doc['ss_event_path'] ?? null)) {
                $out[$doc['ss_event_path']] = $doc;
            }
        }

        return $out;
    }

    /**
     * «Música │ Hispanidad 2026 │ Un océano de música»: el tipo delante sobra
     * (ya es el tipo del evento) y las barras se quedan en puntos.
     */
    private function title(string $raw): ?string
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/\s*[│|]\s*/u', $raw) ?: []), fn ($p) => $p !== ''));
        if (count($parts) > 1 && preg_match('/^(m[uú]sica|teatro|danza|cine|exposici[oó]n|circo|espect[aá]culo|lectura escenificada)$/iu', $parts[0])) {
            array_shift($parts);
        }
        // Sierra Norte lo pone con dos puntos: «Música,flamenco: XXI FESTIVAL…».
        $parts[0] = (string) preg_replace('/^(m[uú]sica|teatro|danza|cine|circo)(\s*,\s*[\pL]+)?\s*:\s*/iu', '', $parts[0] ?? '');

        return Html::clean(implode(' · ', $parts), 200);
    }

    /**
     * El mapa no trae el tipo: se deduce de la ruta y el título, que en estos
     * centros empiezan casi siempre por él («teatro-…», «Danza │ …»).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function classify(string $path, string $title): array
    {
        $text = mb_strtolower($path . ' ' . $title);

        return match (true) {
            (bool) preg_match('/flamenc|cante|cantaor|bulería/u', $text)        => ['events-flamenco', 'events-flamenco-show'],
            (bool) preg_match('/visitas? guiadas?|visitas? teatralizadas?/u', $text) => ['events-experiences', 'events-experiences-guided-tours'],
            (bool) preg_match('/\bexposici[oó]n|\/exposicion/u', $text)         => self::TYPES['Exposición/Museo'],
            (bool) preg_match('/\bdanza|break ?dance|breaking/u', $text)        => self::TYPES['Danza/Baile'],
            (bool) preg_match('/\bcirco\b/u', $text)                           => ['events-circus', null],
            (bool) preg_match('/\b(magia|mago)\b/u', $text)                    => ['events-stage', 'events-stage-magic'],
            (bool) preg_match('/\bcine\b|proyecci[oó]n|cortometraje/u', $text)  => self::TYPES['Cine/Vídeo'],
            (bool) preg_match('/\bteatro|lectura (escenificada|dramatizada)/u', $text) => self::TYPES['Teatro'],
            (bool) preg_match('/\b(humor|mon[oó]logo|comedia)\b/u', $text)      => self::TYPES['Comedia/Humor'],
            (bool) preg_match('/\bmercado|\bferia\b/u', $text)                  => self::TYPES['Mercado/Feria'],
            default                                                             => ['events-small-concerts', null],
        };
    }

    /** @return array{0: string, 1: string}|null */
    private function kids(string $text): ?array
    {
        $text = mb_strtolower($text);
        if (!preg_match(self::KIDS, $text)) {
            return null;
        }

        return match (true) {
            (bool) preg_match('/cuentacuentos|cuentos\b/u', $text) => ['events-kids', 'events-kids-storytelling'],
            (bool) preg_match('/\btaller/u', $text)                => ['events-kids', 'events-kids-workshops'],
            (bool) preg_match('/teatro|t[ií]teres|marionetas|circo|magia/u', $text) => ['events-kids', 'events-kids-theater'],
            default                                                => ['events-kids', 'events-kids-family-plans'],
        };
    }

    /** @return list<\DateTimeImmutable> los pases, en hora de Madrid y en orden */
    private function dates(mixed $raw, \DateTimeZone $tz): array
    {
        $dates = [];
        foreach (is_array($raw) ? $raw : [] as $d) {
            if (($date = $this->date($d)) !== null) {
                $dates[] = $date->setTimezone($tz);
            }
        }
        sort($dates);

        return $dates;
    }

    private function date(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($raw))->setTimezone(new \DateTimeZone('Europe/Madrid'));
        } catch (\Exception) {
            return null;
        }
    }
}
