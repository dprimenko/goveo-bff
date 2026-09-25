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
 * Agenda cultural de la Comunidad de Madrid (comunidad.madrid/actividades).
 *
 * El buscador tiene 13.000 actividades de toda la región —bibliotecas,
 * hospitales, cursos— en 550 páginas de HTML. Lo que se lee es el JSON que
 * pinta su mapa (`/api/events-map`), que acepta los mismos filtros que el
 * buscador: **sólo el municipio de Madrid y los tipos de ocio** (`TYPES`), unas
 * 170. Trae título, ficha, coordenadas y cada pase con su hora (en UTC).
 *
 * Lo que el mapa no trae —sala, cartel, descripción y hasta cuándo dura una
 * exposición— está en los datos estructurados de la ficha, que se abre sólo
 * para lo que tiene algo en los próximos `LOOKAHEAD_DAYS` días: el sitio pide
 * 10 s entre páginas en su `robots.txt`, y abrir fichas de enero en septiembre
 * es trabajo tirado (entrarán en una pasada posterior).
 */
final class ComunidadMadridSource implements EventSource
{
    private const MAP  = 'https://www.comunidad.madrid/api/events-map';
    private const HOME = 'https://www.comunidad.madrid';

    private const LOOKAHEAD_DAYS = 45;

    /**
     * Tipo de actividad del buscador → tipo y subnivel de Goveo. Los que no
     * están (talleres, conferencias, cuentacuentos…) no se piden.
     *
     * @var array<string, array{0: string, 1: ?string}>
     */
    private const TYPES = [
        'Teatro'           => ['events-stage', 'events-stage-theater'],
        'Música'           => ['events-small-concerts', null],
        'Espectáculo'      => ['events-stage', null],
        'Danza/Baile'      => ['events-stage', 'events-stage-dance'],
        'Comedia/Humor'    => ['events-stage', 'events-stage-comedy'],
        'Exposición/Museo' => ['events-art', 'events-art-temporary'],
        'Cine/Vídeo'       => ['events-experiences', 'events-experiences-cinema'],
        'Mercado/Feria'    => ['events-markets', 'events-markets-fairs'],
        'Festival'         => [null, null],
    ];

    /**
     * Salas que ya importa otra fuente: los Teatros del Canal, las salas con
     * lector propio y las municipales (`madrid-datos`).
     */
    private const COVERED = '/teatros del canal|sh[oô]ko|teatro real|zarzuela|reina sof[ií]a|thyssen|joy eslava|'
        . 'sala villanos|la riviera|calder[oó]n|clamores|caf[eé] central|caf[eé] berl[ií]n|cardamomo|corral de la morer[ií]a|'
        . 'torres bermejas|fabrik|independance|moby dick|siroco|specka|teatro flamenco madrid|fundaci[oó]n telef[oó]nica|'
        . 'abad[ií]a|teatro la latina|teatro marquina|teatro pr[ií]ncipe gran v[ií]a|teatro espa[nñ]ol|naves del espa|fern[aá]n g[oó]mez|conde duque|matadero|circo price|centrocentro/iu';

    /** Escenarios al aire libre: allí, lo de la Hispanidad son sus fiestas. */
    private const OUTDOORS = '/^(plaza|puerta del sol|parque|jardines|paseo|calle)\b/iu';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'comunidad-madrid';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $query = ['f[0]=' . rawurlencode('municipio:Madrid')];
        foreach (array_keys(self::TYPES) as $i => $type) {
            $query[] = sprintf('f[%d]=%s', $i + 1, rawurlencode('tipo_actividad:' . $type));
        }

        $raw  = $this->web->get(self::MAP . '?' . implode('&', $query), 20 * 1024 * 1024);
        $data = $raw !== null ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_array($data['docs'] ?? null)) {
            throw new \RuntimeException('No se pudo leer el mapa de actividades de comunidad.madrid');
        }

        $tz      = new \DateTimeZone('Europe/Madrid');
        $today   = new \DateTimeImmutable('today', $tz);
        $horizon = $today->modify(sprintf('+%d days', self::LOOKAHEAD_DAYS));
        $passes  = [];

        foreach ($data['docs'] as $doc) {
            $path   = is_string($doc['ss_event_path'] ?? null) ? $doc['ss_event_path'] : null;
            $point  = $this->point($doc);
            $dates  = $this->dates($doc['dm_recurring_dates'] ?? [], $tz);
            $coming = array_values(array_filter($dates, fn ($d) => $d >= $today));
            if ($path === null || $point === null || $dates === []) {
                continue;
            }
            // Todo lo próximo cae después del horizonte: ya entrará. Si todo es
            // pasado puede ser una exposición abierta (el mapa sólo da el primer
            // día): hay que abrir la ficha para saber hasta cuándo.
            if ($coming !== [] && $coming[0] > $horizon) {
                continue;
            }

            array_push($passes, ...$this->performances(self::HOME . $path, $point, $coming === [] ? [end($dates)] : $coming, $tz));
        }

        return Shows::group($passes);
    }

    /** La ficha ya se leyó en `fetch`: hacía falta la sala. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    /**
     * Ninguna sala se da de alta: la agenda mezcla teatros con academias,
     * parroquias, parques y escenarios de fiestas, casi todos con uno o dos
     * actos. Los eventos se asignan igual a la sala si ya está en Goveo.
     */
    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        return null;
    }

    /**
     * Los pases de una actividad, con lo que dice su ficha.
     *
     * @param array{0: float, 1: float}  $point
     * @param list<\DateTimeImmutable>   $dates
     *
     * @return list<ScrapedEvent>
     */
    private function performances(string $url, array $point, array $dates, \DateTimeZone $tz): array
    {
        $html = $this->web->get($url);
        $node = $html !== null ? (JsonLd::events($html)[0] ?? null) : null;
        if ($node === null) {
            return [];
        }

        $xp    = Html::xpath($html);
        $title = Html::clean(JsonLd::text($node['name'] ?? null, 'name'), 200);
        // El `location.name` de los datos suele ser la dirección otra vez: el
        // nombre de la sala está en el bloque «Ubicación» de la ficha.
        $venue = (string) Html::text($xp, '//div[' . Html::hasClass('programming-information--location') . ']//p[' . Html::hasClass('field--content--highlight') . ']');
        // «Pendiente»: sala aún sin decidir.
        $venue = preg_match('/^(pendiente|por confirmar)/iu', $venue) ? '' : $venue;
        // Entradas, cuando se venden fuera (la taquilla del teatro).
        $tickets = Html::attr($xp, '//a[starts-with(@aria-label, "Entradas")]', 'href');
        $type  = JsonLd::text($node['additionalType'] ?? null) ?? '';
        $end   = $this->date($node['endDate'] ?? null, $tz);
        // «Evento test TDC» en «Ubicación de prueba»: pruebas publicadas por error.
        if ($title === null || preg_match(self::COVERED, $venue) || preg_match('/\btest\b|de prueba/iu', $title . ' ' . $venue)) {
            return [];
        }

        $address = Html::clean(JsonLd::text($node['location']['address'] ?? null, 'streetAddress'));
        // El filtro de municipio del buscador deja pasar actos de Coslada o
        // Alcalá (los organiza «Madrid»): lo que manda es el código postal.
        if (preg_match('/\b28(\d)\d{2}\b/', (string) $address, $m) && $m[1] !== '0') {
            return [];
        }

        $description = Html::clean(JsonLd::text($node['description'] ?? null, 'text'), 400);
        // El `og:image` es 16:9; el de los datos, una franja 3:1 que en el
        // cartel vertical se queda en nada.
        $image = Html::absolute(Html::attr($xp, '//meta[@property="og:image"]', 'content') ?? JsonLd::text($node['image'] ?? null), self::HOME);
        $outdoors    = (bool) preg_match(self::OUTDOORS, $venue);
        [$subcategory, $subtype] = $outdoors && str_contains(mb_strtolower($title . ' ' . $description), 'hispanidad')
            ? ['events-festivities', 'events-festivities-hispanidad']
            : $this->classify($type, $title);
        // Una plaza no es la sala de nadie: con su nombre, el concierto de la
        // Plaza Mayor acababa en el restaurante que se llama igual.
        if ($outdoors) {
            $venue = '';
        }

        // Una exposición sale en el mapa con un pase y en la ficha con su fin:
        // es un rango, no un pase suelto.
        $ranged = count($dates) === 1 && $end !== null && $end->format('Y-m-d') > $dates[0]->format('Y-m-d');

        $out = [];
        foreach ($dates as $start) {
            $allDay = $start->format('H:i') === '00:00';
            $out[] = new ScrapedEvent(
                source: $this->name(),
                externalId: trim((string) parse_url($url, \PHP_URL_PATH), '/'),
                title: $title,
                start: $start,
                end: match (true) {
                    $ranged => $end->format('H:i') === '00:00' ? $end->setTime(23, 59) : $end,
                    $allDay => $start->setTime(23, 59),
                    default => null,
                },
                city: $this->city(),
                venueName: $venue,
                latitude: $point[0],
                longitude: $point[1],
                link: $tickets ?? $url,
                linkAction: $tickets !== null ? 'buy' : 'info',
                description: $description,
                imageUrl: $image,
                detailUrl: $url,
                venueAddress: $address !== null ? trim($address . ', ' . $this->city(), ' ,') : null,
                subcategory: $subcategory,
                subtype: $subtype,
            );
        }

        return $out;
    }

    /**
     * El tipo del buscador, y el subnivel afinado por el título: el tipo
     * «Exposición/Museo» incluye las visitas guiadas, y «Espectáculo» el circo.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function classify(string $type, string $title): array
    {
        $title = mb_strtolower($title);

        return match (true) {
            str_contains($title, 'flamenco')                          => ['events-flamenco', 'events-flamenco-show'],
            (bool) preg_match('/\bvisitas? (guiada|teatralizada)/u', $title) => ['events-experiences', 'events-experiences-guided-tours'],
            (bool) preg_match('/\b(circo|magia|mago)\b/u', $title)    => ['events-stage', 'events-stage-magic'],
            default                                                   => self::TYPES[$type] ?? [null, null],
        };
    }

    /** `POINT (lng lat)` o `"lat,lng"` → `[lat, lng]`. */
    private function point(array $doc): ?array
    {
        foreach (['ss_location_map', 'ss_location_map_center'] as $key) {
            if (preg_match('/POINT\s*\((-?[\d.]+)\s+(-?[\d.]+)\)/', (string) ($doc[$key] ?? ''), $m)) {
                return [(float) $m[2], (float) $m[1]];
            }
        }
        if (preg_match('/^(-?[\d.]+),(-?[\d.]+)$/', (string) ($doc['ss_location_center_latlon'] ?? ''), $m)) {
            return [(float) $m[1], (float) $m[2]];
        }

        return null;
    }

    /** @return list<\DateTimeImmutable> los pases, en hora de Madrid y en orden */
    private function dates(mixed $raw, \DateTimeZone $tz): array
    {
        $dates = array_values(array_filter(array_map(fn ($d) => $this->date($d, $tz), is_array($raw) ? $raw : [])));
        sort($dates);

        return $dates;
    }

    private function date(mixed $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($raw))->setTimezone($tz);
        } catch (\Exception) {
            return null;
        }
    }
}
