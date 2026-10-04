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
 * Red de Teatros de la Comunidad de Madrid (madrid.org/clas_artes/red): la
 * programación **de adultos** del semestre en los teatros municipales de ~75
 * municipios —Alcobendas, Alcorcón, Fuenlabrada, Las Rozas, Majadahonda,
 * Aranjuez, Torrejón, San Sebastián de los Reyes…—. Con ella no hace falta leer
 * la agenda de cada ayuntamiento, que casi ninguno publica limpia.
 *
 * Lo de **público familiar** lo lee `red-teatros` (desde `familiar.html`): aquí
 * se salta, para no traerlo dos veces.
 *
 * Es HTML estático y ordenado: una página por municipio (`municipios.html` las
 * enlaza) con sus salas —nombre, dirección, web y un mapa de Google cuyas
 * coordenadas van en la URL (`!2d<lng>!3d<lat>`)— y, por sala, cada función con
 * su día, hora y género. La ficha del espectáculo, compartida por todos los
 * municipios que lo programan, trae la foto y la sinopsis: se abre en `enrich`,
 * una vez por espectáculo.
 *
 * Las fechas no llevan año: sale de la cabecera («Programación 2º Semestre
 * 2026»); deducirlo como `SpanishDate` mandaría julio al año que viene.
 *
 * Lo que ya lee otra fuente se salta: los municipios con agenda propia
 * (`OWN_SOURCE`: Alcalá) y las salas de la propia Comunidad —Paco Rabal, Pilar Miró, el
 * Real Coliseo, Sierra Norte—, que trae `comunidad-madrid-centros` con toda su
 * programación (su web es la de la Comunidad).
 */
final class RedTeatrosMunicipiosSource implements EventSource
{
    private const BASE = 'https://www.madrid.org/clas_artes/red/';

    /** Páginas de municipio cuya agenda entera lee otra fuente (`alcala`). */
    private const OWN_SOURCE = ['alcala.html'];

    /** Salas de la Comunidad: van por `comunidad-madrid-centros`. */
    private const REGION_SITE = '#(madrid\.org/(agenda-cultural|clas_artes/teatros)|comunidad\.madrid)#i';

    /**
     * Espacios de una página que no son la sala de la ficha (otro edificio del
     * municipio) y de los que se sabe dónde están. Uno que no esté aquí ni case
     * con una sala —una plaza, «Otros espacios»— se descarta: sin coordenadas
     * fiables, «Cómo llegar» llevaría al teatro equivocado.
     *
     * @var array<string, array{0: string, 1: string, 2: float, 3: float}> «página|espacio» => [nombre, dirección, lat, lng]
     */
    private const EXTRA_SPACES = [
        'alcorcon.html|centro cultural vinagrande'       => ['Centro Cultural Viñagrande', 'C. Parque Ordesa, 5, 28924 Alcorcón', 40.3482819, -3.8049202],
        'alcorcon.html|auditorio paco de lucia'          => ['Auditorio Paco de Lucía', 'C. Parque Ferial, 4, 28923 Alcorcón', 40.3369154, -3.8225421],
        'alcobendas.html|centro cultural pablo iglesias' => ['Centro Cultural Pablo Iglesias', 'P.º de la Chopera, 59, 28100 Alcobendas', 40.5430007, -3.6439881],
    ];

    /** Palabras que no distinguen una sala de otra al casar «espacio» con «sala». */
    private const GENERIC = ['teatro', 'auditorio', 'auditorium', 'municipal', 'centro', 'cultural', 'sala', 'casa', 'cultura',
        'espacio', 'multifuncional', 'de', 'del', 'la', 'las', 'los', 'el', 'y', 'cc', 'real', 'otros', 'espacios'];

    /** Al aire libre o sin sitio: no se sabe dónde exactamente. */
    private const OUTDOORS = '/^(plaza|parque|jard[ií]n|recinto|calle|anfiteatro|otros espacios)\b/iu';

    /** Una sala con menos funciones en el semestre no se da de alta: va a la Agenda. */
    private const MIN_SHOWS_FOR_VENUE = 3;

    /** @var array<string, array{name: string, city: string, address: string, lat: float, lng: float, website: ?string, shows: int}> */
    private array $venues = [];

    /** @var array<string, array{0: ?string, 1: ?string}> ficha => [imagen, sinopsis] */
    private array $sheets = [];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'red-teatros-municipios';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $index = $this->web->get(self::BASE . 'municipios.html');
        if ($index === null) {
            throw new \RuntimeException('No se pudo descargar la Red de Teatros de la Comunidad de Madrid');
        }

        $pages = [];
        foreach (Html::xpath($index)->query('//a[@href]') as $a) {
            $href = $a instanceof \DOMElement ? $a->getAttribute('href') : '';
            if (preg_match('/^[a-z0-9-]+\.html$/', $href) && !in_array($href, ['index.html', 'municipios.html', 'familiar.html', ...self::OWN_SOURCE], true)) {
                $pages[$href] = true;
            }
        }

        $parsed = [];
        $sites  = [];
        foreach (array_keys($pages) as $page) {
            if (($html = $this->web->get(self::BASE . $page)) === null) {
                continue;
            }
            $parsed[$page] = $this->parse($page, $html);
            foreach ($parsed[$page] as $show) {
                if ($show['website'] !== null) {
                    $sites[(string) parse_url($show['website'], \PHP_URL_HOST)][$page] = true;
                }
            }
        }

        $performances = [];
        foreach ($parsed as $page => $shows) {
            foreach ($shows as $show) {
                // La misma web en varios municipios es un error de la ficha (la
                // de Colmenar de Oreja sale en cinco): mejor sin web que con otra.
                $website = $show['website'];
                if ($website !== null && count($sites[(string) parse_url($website, \PHP_URL_HOST)] ?? []) > 1) {
                    $website = null;
                }

                $key = $this->key($show['venue'], $show['city']);
                $this->venues[$key] ??= [
                    'name' => $show['venue'], 'city' => $show['city'], 'address' => $show['address'],
                    'lat' => $show['lat'], 'lng' => $show['lng'], 'website' => $website, 'shows' => 0,
                ];
                ++$this->venues[$key]['shows'];

                if (preg_match('/familiar|infantil/iu', $show['genre'])) {
                    continue;
                }
                [$subcategory, $subtype] = $this->classify($show['genre'], $show['title']);

                $performances[] = new ScrapedEvent(
                    source: $this->name(),
                    // El espectáculo en esa sala: el mismo en otro municipio es
                    // otro evento (otro sitio, otro día).
                    externalId: substr($page, 0, -5) . ':' . $this->slug($show['venue']) . ':' . substr(basename($show['sheet']), 0, -5),
                    title: $show['title'],
                    start: $show['start'],
                    end: null,
                    city: $show['city'],
                    venueName: $show['venue'],
                    latitude: $show['lat'],
                    longitude: $show['lng'],
                    link: $show['sheet'],
                    linkAction: 'info',
                    description: $show['company'] !== '' ? $show['company'] . '.' : null,
                    detailUrl: $show['sheet'],
                    venueAddress: $show['address'] . ', ' . $show['city'],
                    subcategory: $subcategory,
                    subtype: $subtype,
                );
            }
        }

        return Shows::group($performances);
    }

    /** La ficha del espectáculo: la primera foto y la sinopsis. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        $url = (string) $event->detailUrl;
        if (!isset($this->sheets[$url])) {
            $html = $this->web->get($url);
            if ($html === null) {
                return $event;
            }
            $xp       = Html::xpath($html);
            $image    = Html::attr($xp, '//div[' . Html::hasClass('carousel-inner') . ']//img', 'src');
            $synopsis = [];
            foreach ($xp->query('//section[' . Html::hasClass('ficha') . ']//div[' . Html::hasClass('col-sm-8') . ']/p') as $p) {
                $text = trim($p->textContent);
                if ($text !== '' && !preg_match('/^sinopsis$/iu', $text)) {
                    $synopsis[] = $text;
                }
            }

            $this->sheets[$url] = [
                $image !== null ? Html::absolute(preg_match('#^(https?:)?//#', $image) ? $image : self::BASE . ltrim($image, '/'), self::BASE) : null,
                Html::clean(implode(' ', $synopsis), 400),
            ];
        }

        [$image, $synopsis] = $this->sheets[$url];

        return $event->withDetails($image, $synopsis);
    }

    /** Sólo las salas con temporada (varias funciones): un salón de actos con una no. */
    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        $venue = $this->venues[$this->key($event->venueName, $event->city)] ?? null;
        if ($venue === null || $venue['shows'] < self::MIN_SHOWS_FOR_VENUE) {
            return null;
        }

        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue-' . $this->slug($venue['city']) . '-' . $this->slug($venue['name']),
            name: $venue['name'],
            city: $venue['city'],
            categorySlug: 'culture-shows',
            latitude: $venue['lat'],
            longitude: $venue['lng'],
            address: $venue['address'] . ', ' . $venue['city'],
            website: $venue['website'],
        );
    }

    /**
     * Las funciones de una página de municipio.
     *
     * @return list<array{title: string, company: string, genre: string, start: \DateTimeImmutable, sheet: string, venue: string, city: string, address: string, lat: float, lng: float, website: ?string}>
     */
    private function parse(string $page, string $html): array
    {
        $xp   = Html::xpath($html);
        $h1   = $xp->query('//h1')->item(0);
        $city = $h1 !== null ? trim((string) $h1->firstChild?->textContent) : '';
        if ($city === '' || !preg_match('/Semestre\s+(\d{4})/u', (string) $h1?->textContent, $y)) {
            return [];
        }
        $year = (int) $y[1];
        $tz   = new \DateTimeZone('Europe/Madrid');

        $out = [];
        foreach ($xp->query('//div[' . Html::hasClass('espacios-bloque') . ']') as $block) {
            // Las salas de la columna izquierda: nombre, dirección, web, mapa.
            $halls = [];
            foreach ($xp->query('.//h3', $block) as $h3) {
                $info = $xp->query('following-sibling::ul[1]', $h3)->item(0);
                $map  = $info !== null ? Html::attr($xp, './/iframe', 'src', $info) : null;
                $site = $info !== null ? Html::attr($xp, './/a[i[' . Html::hasClass('fa-globe') . ']]', 'href', $info) : null;
                if ($map === null || !preg_match('/!2d(-?[\d.]+)!3d(-?[\d.]+)/', $map, $m) || ($site !== null && preg_match(self::REGION_SITE, $site))) {
                    continue;
                }
                $halls[] = [
                    // «<strong>Auditorio Joaquín Rodrigo</strong><br>Centro Cultural…»: el primero.
                    'name'    => $this->hallName(trim(preg_split('/\R/u', trim($h3->textContent))[0] ?? ''), $city),
                    'address' => (string) preg_replace('/\s+/u', ' ', (string) Html::text($xp, './/li[i[' . Html::hasClass('fa-map-marker') . ']]', $info)),
                    'lat'     => (float) $m[2],
                    'lng'     => (float) $m[1],
                    'website' => $site !== null && preg_match('#^https?://#', $site) ? trim($site) : null,
                ];
            }
            if ($halls === []) {
                continue;
            }

            // La lista de la derecha: un rótulo por espacio y sus funciones debajo.
            $hall = null;
            foreach ($xp->query('.//div[' . Html::hasClass('espacios-info-lista') . ']/ul/li', $block) as $li) {
                if (!$li instanceof \DOMElement) {
                    continue;
                }
                if (str_contains(' ' . $li->getAttribute('class') . ' ', ' nombreespacio ')) {
                    $hall = $this->hall($page, trim((string) $li->firstChild?->textContent), $halls);
                    continue;
                }
                $a = $xp->query('./a[@href]', $li)->item(0);
                if ($hall === null || !$a instanceof \DOMElement) {
                    continue;
                }

                $title = Html::clean(Html::text($xp, './/li[' . Html::hasClass('first-child') . ']', $a), 200);
                $who   = $xp->query('.//li[' . Html::hasClass('2-child') . ']', $a)->item(0);
                $lines = $who !== null ? array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $who->textContent))))) : [];
                $when  = (string) Html::text($xp, './/ul[' . Html::hasClass('espacios-cel-right') . ']', $a);
                $start = $this->start($when, $year, $tz);
                if ($title === null || $start === null) {
                    continue;
                }

                $out[] = [
                    'title'   => $title,
                    'company' => count($lines) > 1 ? $lines[0] : '',
                    'genre'   => (string) end($lines),
                    'start'   => $start,
                    'sheet'   => self::BASE . ltrim($a->getAttribute('href'), '/'),
                    'venue'   => $hall['name'],
                    'city'    => $city,
                    'address' => $hall['address'],
                    'lat'     => $hall['lat'],
                    'lng'     => $hall['lng'],
                    'website' => $hall['website'] ?? null,
                ];
            }
        }

        return $out;
    }

    /**
     * La sala de un rótulo de espacio: la de la ficha que comparte una palabra
     * que la distinga («Buero Vallejo»), la que se llama como un trozo del
     * rótulo («Auditorio Municipal» en «Auditorio Municipal Raphael»), un
     * edificio conocido (`EXTRA_SPACES`) o, si la página sólo tiene una sala y
     * el rótulo no dice otra cosa que el municipio («Teatro Municipal de Tres
     * Cantos»), ésa.
     *
     * @param list<array{name: string, address: string, lat: float, lng: float, website: ?string}> $halls
     *
     * @return array{name: string, address: string, lat: float, lng: float, website: ?string}|null
     */
    private function hall(string $page, string $label, array $halls): ?array
    {
        $words = $this->words($label);
        $own   = array_diff($words, self::GENERIC);

        $byWord = array_values(array_filter($halls, fn ($h) => array_intersect($own, array_diff($this->words($h['name']), self::GENERIC)) !== []));
        if (count($byWord) === 1) {
            return $byWord[0];
        }
        foreach ($halls as $h) {
            $name = array_diff($this->words($h['name']), ['de', 'del', 'la', 'las', 'los', 'el', 'y']);
            if ($name !== [] && array_diff($name, $words) === []) {
                return $h;
            }
        }
        if (isset(self::EXTRA_SPACES[$page . '|' . implode(' ', $words)])) {
            [$name, $address, $lat, $lng] = self::EXTRA_SPACES[$page . '|' . implode(' ', $words)];

            return ['name' => $name, 'address' => $address, 'lat' => $lat, 'lng' => $lng, 'website' => null];
        }
        if (preg_match(self::OUTDOORS, $label) || count($halls) !== 1) {
            return null;
        }
        // Lo que queda del rótulo tiene que ser el nombre del municipio.
        $city = $this->words(substr($page, 0, -5));
        foreach ($own as $w) {
            if (!array_filter($city, fn ($c) => str_contains($c, $w) || str_contains($w, $c))) {
                return null;
            }
        }

        return $halls[0];
    }

    /**
     * El nombre de la sala tal como irá en su ficha de negocio: sin gritos
     * («AUDITORIO CENTRO MUNICIPAL JOAN MANUEL SERRAT») y, si es genérico
     * («Teatro Municipal», «Casa de Cultura»), con su municipio: hay decenas.
     */
    private function hallName(string $name, string $city): string
    {
        if (mb_strtoupper($name) === $name) {
            $name = (string) preg_replace_callback(
                '/(?<=\s)(De|Del|La|Las|Los|El|Y)(?=\s)/u',
                fn ($m) => mb_strtolower($m[1]),
                mb_convert_case(mb_strtolower($name), \MB_CASE_TITLE),
            );
        }
        if (array_diff($this->words($name), self::GENERIC) === []) {
            $name = trim((string) preg_replace('/\s*\(.*\)$/u', '', $name)) . ' de ' . $city;
        }

        return $name;
    }

    /** «4 de octubre - 19:00 h.» (a veces sin espacio antes del guion). */
    private function start(string $text, int $year, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!preg_match('/(\d{1,2})\s+de\s+(\pL+)/u', $text, $m) || ($month = SpanishDate::month($m[2])) === null || !checkdate($month, (int) $m[1], $year)) {
            return null;
        }
        $date = (new \DateTimeImmutable('now', $tz))->setDate($year, $month, (int) $m[1])->setTime(0, 0);

        return preg_match('/(\d{1,2})[:.](\d{2})/', $text, $t) ? $date->setTime((int) $t[1], (int) $t[2]) : $date;
    }

    /**
     * El género del listado («Teatro.», «Música.», «Circo.») → tipo y
     * subnivel; el flamenco, el musical y la comedia, por el título.
     *
     * @return array{0: string, 1: ?string}
     */
    private function classify(string $genre, string $title): array
    {
        $genre = mb_strtolower($genre);
        $title = mb_strtolower($title);

        return match (true) {
            str_contains($title, 'flamenc')                                  => ['events-flamenco', 'events-flamenco-show'],
            str_contains($genre, 'danza')                                    => ['events-stage', 'events-stage-dance'],
            str_contains($genre, 'circo')                                    => ['events-circus', null],
            str_contains($genre, 'magia')                                    => ['events-stage', 'events-stage-magic'],
            str_contains($genre, 'música')                                   => ['events-small-concerts', null],
            (bool) preg_match('/\bmusical\b/u', $title)                      => ['events-stage', 'events-stage-musicals'],
            (bool) preg_match('/\b(mon[oó]logo|humor|comedia|c[oó]mic)/u', $title) => ['events-stage', 'events-stage-comedy'],
            default                                                          => ['events-stage', 'events-stage-theater'],
        };
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        return array_values(array_filter(explode('-', $this->slug($text)), fn ($w) => $w !== ''));
    }

    private function key(string $venue, string $city): string
    {
        return $this->slug($city) . '|' . $this->slug($venue);
    }

    private function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', 'à' => 'a', 'è' => 'e']);

        return trim(preg_replace('/[^a-z0-9]+/', '-', $text) ?? '', '-');
    }
}
