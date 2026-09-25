<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * esMadrid, la agenda turística oficial de la ciudad. No se lee la web: publica
 * la agenda entera como datos abiertos (`/opendata/agenda_v1_es.xml`, ~1.100
 * eventos, ~4,5 MB) con todo lo que hace falta —sala (`nombrert`),
 * coordenadas, dirección, imagen, categoría, rangos de fechas y horario—. Una
 * sola petición: su `robots.txt` pide 10 s entre páginas y abrir fichas una a
 * una serían horas.
 *
 * Tres trampas:
 *
 * - **Mezcla de todo**: partidos, macroconciertos de estadio, ferias
 *   profesionales. Lo grande se quita (`BIG_VENUES`, «conciertos grandes» no
 *   entra), y lo profesional por su categoría (`EXCLUDED`).
 * - **Repite lo que ya leen otras fuentes** (el Ayuntamiento, los Teatros del
 *   Canal, las salas con lector propio…): esas salas se saltan (`COVERED`) para
 *   no importar dos veces el mismo evento con otro `external_ref`. Si se añade
 *   una fuente de sala nueva, hay que apuntarla aquí.
 * - **El horario es texto libre** («21:00 h», «Martes a domingos: 19:30 h»,
 *   «Consultar página oficial»). Se toma la primera hora de inicio; un horario
 *   de apertura («10:00 - 20:00 h», museos y ferias) es un evento de día entero.
 */
final class EsMadridSource implements EventSource
{
    private const FEED = 'https://www.esmadrid.com/opendata/agenda_v1_es.xml';

    /** Recintos de estadio y pabellón: partidos y conciertos grandes. */
    private const BIG_VENUES = [
        'movistar arena', 'wizink', 'estadio', 'vistalegre', 'ciudad real madrid', 'centro deportivo',
        'iberdrola music',
    ];

    /**
     * Salas que ya importa otra fuente (por su nombre en esMadrid, sin tildes).
     * Sin esto, cada obra del Teatro Español entraría dos veces: por
     * `madrid-datos` y por aquí.
     */
    private const COVERED = [
        // Ayuntamiento (`madrid-datos`).
        'teatro espanol', 'naves del espanol', 'fernan gomez', 'conde duque', 'matadero',
        'teatro circo price', 'centrocentro',
        // Salas con fuente propia.
        'ifema', 'teatro de la abadia', 'teatros del canal', 'la riviera', 'teatro calderon', 'teatro real',
        'teatro de la zarzuela', 'sala villanos', 'joy eslava', 'teatro eslava', 'thyssen', 'reina sofia',
        'palacio de cristal', 'palacio de velazquez', 'fundacion telefonica', 'cafe central',
        'cardamomo', 'villa rosa', 'corral de la moreria', 'torres bermejas', 'clamores',
        'cafe berlin', 'fabrik', 'independance', 'moby dick', 'shoko', 'siroco', 'specka',
        'teatro flamenco madrid', 'tablao flamenco 1911', 'teatro la latina', 'teatro marquina',
        'teatro principe gran via',
        // Gruposmedia.
        'teatro alcazar', 'capitol gran via', 'teatro gran via', 'teatro maravillas', 'teatro figaro',
    ];

    /** Categoría/subcategoría de esMadrid que no es un plan para el público. */
    private const EXCLUDED = [
        'Ferias'  => ['Empresas y negocios', 'Salud'],
        // Partidos de liga: son de estadio y ya se quitan por la sala, pero
        // también los hay en pabellones que no están en la lista.
        'Deporte' => ['Fútbol', 'Baloncesto'],
    ];

    /**
     * Categoría de esMadrid → tipo de Goveo, con el subnivel por subcategoría
     * (la primera que case). Lo que no está aquí va a «Otros».
     *
     * @var array<string, array{0: string, 1: array<string, ?string>}>
     */
    private const TYPES = [
        'Música'            => ['events-small-concerts', []],
        'Teatro y danza'    => ['events-stage', [
            'Musical'       => 'events-stage-musicals',
            'Humor'         => 'events-stage-comedy',
            'Comedia'       => 'events-stage-comedy',
            'Danza moderna' => 'events-stage-dance',
            'Ballet'        => 'events-stage-dance',
            'Circo'         => 'events-stage-magic',
            'Magia'         => 'events-stage-magic',
            '*'             => 'events-stage-theater',
        ]],
        'Exposiciones'      => ['events-art', [
            'Fotografía' => 'events-art-photography',
            'Inmersivo'  => 'events-art-immersive',
            '*'          => 'events-art-temporary',
        ]],
        'Ferias'            => ['events-markets', [
            'Arte, antigüedades y artesanía' => 'events-markets-vintage-crafts',
            '*'                              => 'events-markets-fairs',
        ]],
        'Niños'             => ['events-stage', [
            'Circo' => 'events-stage-magic',
            '*'     => 'events-stage-theater',
        ]],
        'Deporte'           => ['events-experiences', ['*' => 'events-experiences-sport']],
        'Eventos de ciudad' => ['', [
            'Fiestas'     => 'events-festivities',
            'Inmersivo'   => 'events-art-immersive',
            'Arte'        => 'events-art',
            'Gastronomía' => 'events-experiences-gastronomy',
            'Cine'        => 'events-experiences-cinema',
            'Compras'     => 'events-markets',
            'Moda'        => 'events-markets',
        ]],
    ];

    /** Una sala con menos eventos que esto no merece ficha: va a la Agenda. */
    private const MIN_VENUE_EVENTS = 5;

    /** Sitios al aire libre: dan eventos pero no son la sala de nadie. */
    private const OUTDOORS = '/^(plaza|paseo|parque|calle|jard[ií]n|recinto|varios)\b/iu';

    /** @var array<string, array<string, int>> sala => tipo => eventos */
    private array $venueTypes = [];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'esmadrid';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $raw = $this->web->get(self::FEED, 20 * 1024 * 1024);
        $xml = $raw !== null ? @simplexml_load_string($raw, options: \LIBXML_NOCDATA) : false;
        if ($xml === false || !isset($xml->service)) {
            throw new \RuntimeException('No se pudo leer la agenda de datos abiertos de esMadrid');
        }

        $tz     = new \DateTimeZone('Europe/Madrid');
        $events = [];
        $this->venueTypes = [];

        foreach ($xml->service as $s) {
            $event = $this->event($s, $tz);
            if ($event === null) {
                continue;
            }
            $events[] = $event;
            if ($event->venueName !== '') {
                $type = $event->subcategory ?? '';
                $this->venueTypes[$event->venueName][$type] = ($this->venueTypes[$event->venueName][$type] ?? 0) + 1;
            }
        }

        return $events;
    }

    /** El fichero ya lo trae todo; no se abre ninguna ficha (ver la cabecera). */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    /**
     * Las salas con programación de verdad salen como negocio, sin web ni
     * avatar —el fichero no los trae— y con el cartel de su primer evento de
     * escaparate. Las de conciertos, como `nightlife`; el resto, cultura.
     */
    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        $types = $this->venueTypes[$event->venueName] ?? [];
        if ($event->venueName === '' || array_sum($types) < self::MIN_VENUE_EVENTS) {
            return null;
        }

        $music = ($types['events-small-concerts'] ?? 0) + ($types['events-nightlife'] ?? 0);

        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'sala-' . $this->slug($event->venueName),
            name: $event->venueName,
            city: $this->city(),
            categorySlug: $music * 2 > array_sum($types) ? 'nightlife' : 'culture-business',
            latitude: $event->latitude,
            longitude: $event->longitude,
            address: $event->venueAddress,
        );
    }

    private function event(\SimpleXMLElement $s, \DateTimeZone $tz): ?ScrapedEvent
    {
        $id    = trim((string) $s['id']);
        $title = Html::clean((string) $s->basicData->title, 200);
        $venue = trim((string) $s->basicData->nombrert);
        $lat   = trim((string) $s->geoData->latitude);
        $lng   = trim((string) $s->geoData->longitude);
        $zip   = trim((string) $s->geoData->zipcode);

        // Sin coordenadas el feed no sabe dónde enseñarlo (filtra por
        // distancia); y fuera de la ciudad (Alcalá, Getafe…) no es de Madrid.
        if ($id === '' || $title === null || !is_numeric($lat) || !is_numeric($lng) || ($zip !== '' && !str_starts_with($zip, '280'))) {
            return null;
        }
        // Sin sala (`nombrert` vacío, uno de cada diez) se mira el título y la
        // dirección de la ficha, que suelen llevarla; lo de estadio, siempre en
        // la dirección también: «Iberdrola Music» es «shakira-estadio-shakira».
        $slug  = basename((string) $s->basicData->web);
        $where = $venue !== '' ? $venue : $title . ' ' . $slug;
        if ($this->matches($venue . ' ' . $slug, self::BIG_VENUES) || $this->matches($where, self::COVERED)) {
            return null;
        }

        $category    = trim((string) ($s->extradata->categorias->categoria[0]?->xpath('item[@name="Categoria"]')[0] ?? ''));
        $subcategory = array_map('strval', $s->extradata->categorias->categoria[0]?->xpath('subcategorias/subcategoria/item[@name="SubCategoria"]') ?? []);
        if (array_intersect(self::EXCLUDED[$category] ?? [], $subcategory) !== []) {
            return null;
        }

        [$start, $end, $weekdays] = $this->dates($s, $tz);
        if ($start === null) {
            return null;
        }
        [$type, $subtype] = $this->classify($category, $subcategory);
        // Una plaza o un paseo no es la sala de nadie: con su nombre, lo de la
        // Plaza Mayor acabaría en el restaurante que se llama igual.
        if (preg_match(self::OUTDOORS, $venue)) {
            $venue = '';
        }

        $body = (string) $s->basicData->body;
        // El pie «Crédito imagen: …» no es parte de la descripción.
        $body = preg_split('/Cr[ée]dito imagen/iu', $body)[0] ?? $body;
        $web  = trim((string) $s->basicData->web);

        return new ScrapedEvent(
            source: $this->name(),
            externalId: $id,
            title: $title,
            start: $start,
            end: $end,
            city: $this->city(),
            venueName: $venue,
            latitude: (float) $lat,
            longitude: (float) $lng,
            link: $web !== '' ? $web : null,
            linkAction: 'info',
            description: Html::clean($body, 400),
            imageUrl: trim((string) ($s->multimedia->media[0]->url ?? '')) ?: null,
            detailUrl: $web !== '' ? $web : null,
            weekdays: $weekdays,
            venueAddress: $this->address($s),
            subcategory: $type,
            subtype: $subtype,
        );
    }

    /**
     * Los rangos (`inicio`, `fin`, `dias` ISO) → del primer día al último, con
     * los días de la semana en que hay algo. La hora sale del horario escrito.
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: ?list<int>}
     */
    private function dates(\SimpleXMLElement $s, \DateTimeZone $tz): array
    {
        $first = $last = null;
        $days  = [];
        foreach ($s->extradata->fechas->rango ?? [] as $r) {
            $from = \DateTimeImmutable::createFromFormat('!d/m/Y', trim((string) $r->inicio), $tz) ?: null;
            $to   = \DateTimeImmutable::createFromFormat('!d/m/Y', trim((string) $r->fin), $tz) ?: $from;
            if ($from === null) {
                continue;
            }
            $first = $first === null || $from < $first ? $from : $first;
            $last  = $last === null || $to > $last ? $to : $last;
            foreach (explode(',', (string) $r->dias) as $d) {
                if (ctype_digit(trim($d))) {
                    $days[(int) trim($d)] = true;
                }
            }
        }
        if ($first === null) {
            return [null, null, null];
        }

        $weekdays = null;
        if (count($days) > 0 && count($days) < 7) {
            $weekdays = array_keys($days);
            sort($weekdays);
        }
        $time = $this->startTime((string) ($s->extradata->xpath('item[@name="Horario"]')[0] ?? ''));

        if ($time === null) {
            // Sin hora, dura su último día entero.
            return [$first, $last->setTime(23, 59), $weekdays];
        }

        $start = $first->setTime($time[0], $time[1]);

        return [$start, $last > $first ? $last->setTime(23, 59) : null, $weekdays];
    }

    /** @return array{0: int, 1: int}|null */
    private function startTime(string $schedule): ?array
    {
        $text = (string) Html::clean($schedule, 1000);

        if (preg_match('/Inicio:\s*(\d{1,2})[:.](\d{2})/iu', $text, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        // «10:00 - 20:00 h» es horario de apertura, no la hora de una función.
        if (preg_match('/\d{1,2}[:.]\d{2}\s*(?:h\s*)?[-–]\s*\d{1,2}[:.]\d{2}/u', $text)) {
            return null;
        }
        if (preg_match('/(\d{1,2})[:.](\d{2})\s*h/iu', $text, $m) && (int) $m[1] < 24) {
            return [(int) $m[1], (int) $m[2]];
        }

        return null;
    }

    /**
     * @param list<string> $subcategories
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function classify(string $category, array $subcategories): array
    {
        if (!isset(self::TYPES[$category])) {
            return [null, null];
        }
        [$type, $subtypes] = self::TYPES[$category];

        // Flamenco manda sobre la categoría: esMadrid lo pone bajo música o danza.
        if (in_array('Flamenco', $subcategories, true)) {
            return ['events-flamenco', 'events-flamenco-show'];
        }

        foreach ($subcategories as $sub) {
            if (isset($subtypes[$sub])) {
                $slug = $subtypes[$sub];
                // «Eventos de ciudad» no tiene tipo propio: lo decide la subcategoría,
                // que a veces es ya un tipo y a veces un subnivel.
                return $type === '' ? [$this->parentOf($slug), $slug === $this->parentOf($slug) ? null : $slug] : [$type, $slug];
            }
        }

        return $type === '' ? [null, null] : [$type, $subtypes['*'] ?? null];
    }

    /** `events-art-immersive` → `events-art`; un tipo se devuelve tal cual. */
    private function parentOf(string $slug): string
    {
        foreach (['events-small-concerts', 'events-nightlife', 'events-stage', 'events-flamenco', 'events-art', 'events-markets', 'events-festivities', 'events-experiences'] as $parent) {
            if ($slug === $parent || str_starts_with($slug, $parent . '-')) {
                return $parent;
            }
        }

        return $slug;
    }

    /** «de Agustín de Foxa, s/n», «28036» → «de Agustín de Foxa, s/n, 28036 Madrid». */
    private function address(\SimpleXMLElement $s): ?string
    {
        $street = trim((string) $s->geoData->address);
        if ($street === '') {
            return null;
        }

        return trim(sprintf('%s, %s %s', $street, trim((string) $s->geoData->zipcode), $this->city()), ' ,');
    }

    /** @param list<string> $needles */
    private function matches(string $venue, array $needles): bool
    {
        $name = $this->slug($venue, ' ');
        foreach ($needles as $needle) {
            if (str_contains($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function slug(string $text, string $glue = '-'): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ô' => 'o']);

        return trim(preg_replace('/[^a-z0-9]+/', $glue, $text) ?? '', $glue);
    }
}
