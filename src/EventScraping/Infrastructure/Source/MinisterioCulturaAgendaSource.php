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
 * Base de los museos estatales cuya web es la del Ministerio de Cultura
 * (Magnolia): el Arqueológico Nacional, el Cerralbo y el del Romanticismo.
 * Comparten el mismo bloque de agenda, y un museo nuevo de esa plataforma es
 * una clase pequeña que dice dónde está su agenda, qué sala es y qué tipos se
 * quedan fuera.
 *
 * Las fechas de las tarjetas son **texto a mano** («Sábado 3 y miércoles 7 de
 * octubre. Punto de encuentro, 17:30»), imposibles de leer con fiabilidad. Pero
 * la agenda acepta `?fecha=DD/MM/AAAA` y devuelve lo que hay **ese día**: se
 * pregunta día a día y los días en que sale cada actividad son sus pases (ver
 * `Shows`). Del texto sólo se toma la hora, si hay una sola, y el fin de una
 * exposición, que puede quedar más allá de los días preguntados.
 */
abstract class MinisterioCulturaAgendaSource implements EventSource
{
    /** Días que se preguntan: los 30 de la pasada y un margen. */
    private const DAYS = 31;

    /**
     * Una actividad que no es exposición y sale más de esta fracción de los
     * días no es una cita: es una oferta fija («Visita autónoma», «Visitas
     * combinadas, de marzo a diciembre»).
     */
    private const PERMANENT_SHARE = 0.6;

    /**
     * Lo que no es un plan para cualquiera: conferencias, cursos, congresos,
     * clubes de lectura, presentaciones de libros.
     */
    private const EXCLUDED = '/conferencia|curso|congreso|mesa redonda|jornadas?\b|seminario|simposio|coloquio|'
        . 'club de lectura|actividad literaria|presentaci[oó]n del libro|presentaci[oó]n de libro|colegios|'
        . 'profesorado|secundaria|bachillerato|discapacidad|tiflol[oó]gic|inclusi[oó]n social|accesibilidad/iu';

    public function __construct(protected readonly WebPage $web) {}

    public function city(): string
    {
        return 'Madrid';
    }

    /** La página de la agenda (la que acepta `?fecha=`). */
    abstract protected function agendaUrl(): string;

    /**
     * La sala: `name`, `lat`, `lng`, `address` y `website`.
     *
     * @return array{name: string, lat: float, lng: float, address: string, website: string}
     */
    abstract protected function venue(): array;

    public function fetch(): iterable
    {
        $tz    = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $tz);
        $venue = $this->venue();

        /** @var array<string, array{card: array<string, string>, days: list<\DateTimeImmutable>}> $seen */
        $seen    = [];
        $fetched = 0;

        for ($i = 0; $i < self::DAYS; ++$i) {
            $day  = $today->modify(sprintf('+%d days', $i));
            $html = $this->web->get($this->agendaUrl() . '?fecha=' . $day->format('d/m/Y'));
            if ($html === null) {
                continue;
            }
            ++$fetched;

            foreach ($this->cards($html) as $card) {
                $seen[$card['url']]['card'] ??= $card;
                $days = $seen[$card['url']]['days'] ?? [];
                // La página del Cerralbo pinta dos listas (exposiciones y
                // actividades): la misma tarjeta no cuenta dos veces el día.
                if (!in_array($day, $days, false)) {
                    $days[] = $day;
                }
                $seen[$card['url']]['days'] = $days;
            }
        }

        if ($fetched === 0) {
            throw new \RuntimeException(sprintf('No se pudo leer la agenda de %s', $venue['name']));
        }

        $passes = [];
        foreach ($seen as $url => ['card' => $card, 'days' => $days]) {
            $about = $card['category'] . ' ' . $card['title'] . ' ' . $card['when'];
            if (preg_match(self::EXCLUDED, $about) || $this->excluded($card)) {
                continue;
            }

            [$subcategory, $subtype] = $this->classify($card);
            $exhibition = $subcategory === 'events-art';
            if (!$exhibition && count($days) > self::PERMANENT_SHARE * $fetched) {
                continue;
            }

            $hour = $exhibition ? null : $this->hour($card['when']);
            // Una exposición acaba cuando dice el texto, aunque sea después de
            // los días preguntados: cada pase lleva ese fin y `Shows` lo hereda.
            $closes = $exhibition ? $this->closes($card['when'], $today) : null;

            foreach ($days as $day) {
                $start = $hour !== null ? $day->setTime($hour[0], $hour[1]) : $day;
                $passes[] = new ScrapedEvent(
                    source: $this->name(),
                    externalId: trim((string) parse_url($url, \PHP_URL_PATH), '/'),
                    title: $card['title'],
                    start: $start,
                    end: $hour !== null ? null : max($closes ?? $day, $day)->setTime(23, 59),
                    city: $this->city(),
                    venueName: $venue['name'],
                    latitude: $venue['lat'],
                    longitude: $venue['lng'],
                    link: $url,
                    linkAction: 'info',
                    description: $card['description'],
                    imageUrl: $card['image'],
                    detailUrl: $url,
                    venueAddress: $venue['address'],
                    subcategory: $subcategory,
                    subtype: $subtype,
                );
            }
        }

        return Shows::group($passes);
    }

    /** Todo sale de la agenda. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        $venue = $this->venue();

        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue-' . $this->name(),
            name: $venue['name'],
            city: $this->city(),
            categorySlug: 'tourism-museums',
            latitude: $venue['lat'],
            longitude: $venue['lng'],
            address: $venue['address'],
            website: $venue['website'],
        );
    }

    /**
     * Lo que se queda fuera además de `EXCLUDED`, propio de cada museo.
     *
     * @param array{url: string, title: string, category: string, when: string, description: ?string, image: ?string} $card
     */
    protected function excluded(array $card): bool
    {
        return false;
    }

    /**
     * Tipo y subnivel por la categoría de la tarjeta y, si no dice bastante
     * (en el Cerralbo la categoría es la edad: «De 6 a 12»), por el título.
     *
     * @param array{url: string, title: string, category: string, when: string, description: ?string, image: ?string} $card
     *
     * @return array{0: string, 1: ?string}
     */
    protected function classify(array $card): array
    {
        $category = mb_strtolower($card['category']);
        $text     = mb_strtolower($card['category'] . ' ' . $card['title'] . ' ' . $card['when']);

        if (str_contains($category, 'exposici')) {
            return preg_match('/fotograf|fot[oó]grafo|photoespa/u', mb_strtolower($card['title'] . ' ' . $card['description']))
                ? ['events-art', 'events-art-photography']
                : ['events-art', 'events-art-temporary'];
        }

        // La edad como categoría («De 6 a 12»): para niños si no pasa de 14.
        $forKids = preg_match('/^de \d+ a (\d+)/u', $category, $age) && (int) $age[1] <= 14;
        if ($forKids || preg_match('/infantil|famili|niñ[oa]s|bebé|bebecuento|\bpeques?\b/u', $text)) {
            return match (true) {
                (bool) preg_match('/cuentacuento|bebecuento|cuento/u', $text) => ['events-kids', 'events-kids-storytelling'],
                (bool) preg_match('/taller/u', $text)                         => ['events-kids', 'events-kids-workshops'],
                (bool) preg_match('/teatro|t[ií]teres|marionetas/u', $text)   => ['events-kids', 'events-kids-theater'],
                default                                                        => ['events-kids', 'events-kids-family-plans'],
            };
        }

        // Manda la categoría cuando dice algo («Cine», «Visitas»); si no,
        // el título y el texto de la fecha.
        $kind = $this->kind($category);

        return $kind[0] !== 'events-other' ? $kind : $this->kind($text);
    }

    /** @return array{0: string, 1: ?string} */
    private function kind(string $text): array
    {
        // La visita y el taller antes que la música o el teatro: «Visita
        // teatralizada», «Taller de creatividad a través de la música».
        return match (true) {
            (bool) preg_match('/visita|recorrido|itinerario|pieza del mes|mediaci[oó]n/u', $text) => ['events-experiences', 'events-experiences-guided-tours'],
            (bool) preg_match('/taller/u', $text)                                               => ['events-experiences', 'events-experiences-workshops'],
            (bool) preg_match('/concierto|m[uú]sica|cuarteto|trío|recital|jazz|piano|[oó]rgano/u', $text) => ['events-small-concerts', null],
            (bool) preg_match('/\bcine\b|pel[ií]cula|proyecci[oó]n|documental/u', $text)        => ['events-experiences', 'events-experiences-cinema'],
            (bool) preg_match('/\bdanza\b/u', $text)                                            => ['events-stage', 'events-stage-dance'],
            (bool) preg_match('/teatro/u', $text) && !str_contains($text, 'teatro real')        => ['events-stage', 'events-stage-theater'],
            default                                                                             => ['events-other', null],
        };
    }

    /**
     * Las tarjetas de la agenda de un día.
     *
     * @return list<array{url: string, title: string, category: string, when: string, description: ?string, image: ?string}>
     */
    private function cards(string $html): array
    {
        $xp   = Html::xpath($html);
        $home = $this->agendaUrl();
        $out  = [];

        foreach ($xp->query('//div[' . Html::hasClass('resultados') . ']/div[' . Html::hasClass('enlace') . ']') as $node) {
            $url   = Html::absolute(Html::attr($xp, './/p[' . Html::hasClass('titulo') . ']//a', 'href', $node), $home);
            $title = Html::clean(Html::text($xp, './/p[' . Html::hasClass('titulo') . ']', $node), 200);
            if ($url === null || $title === null) {
                continue;
            }

            $description = Html::clean(Html::text($xp, './/p[' . Html::hasClass('descripcion') . ']', $node), 400);

            $out[] = [
                'url'         => strtok($url, '#?'),
                'title'       => $title,
                'category'    => (string) Html::text($xp, './/span[' . Html::hasClass('categoria') . ']', $node),
                'when'        => (string) Html::text($xp, './/span[' . Html::hasClass('fecha') . ']', $node),
                // «.»: tarjetas del Arqueológico sin descripción.
                'description' => $description !== null && mb_strlen($description) > 3 ? $description : null,
                'image'       => $this->image(Html::attr($xp, './/img', 'src', $node), $home),
            ];
        }

        return $out;
    }

    /**
     * La imagen de la tarjeta es una miniatura de 256 px; el mismo fichero en
     * el formato para redes sociales (`cabecera-imagenRRSS`) sale a 1200.
     */
    private function image(?string $src, string $home): ?string
    {
        $src = Html::absolute($src, $home);

        return $src === null ? null : (string) preg_replace('#/evento-cln-[hv]/#', '/cabecera-imagenRRSS/', $src);
    }

    /**
     * La hora del texto, sólo si hay una («Salón de actos, 18:00»). Con varias
     * («11:00 a 14:00», «17:30 y 11:00») se deja el día entero antes que
     * elegir mal.
     *
     * @return array{0: int, 1: int}|null
     */
    private function hour(string $when): ?array
    {
        preg_match_all('/\b([01]?\d|2[0-3])[:.]([0-5]\d)\b/', $when, $m, \PREG_SET_ORDER);
        $hours = array_unique(array_map(fn ($x) => sprintf('%02d:%s', $x[1], $x[2]), $m));

        if (count($hours) !== 1) {
            return null;
        }
        [$h, $i] = explode(':', reset($hours));

        return [(int) $h, (int) $i];
    }

    /**
     * El último día de una exposición, del texto de la tarjeta: «27 de
     * mayo-18 de octubre de 2026. Sala…», «Del 12 de junio al 25 de octubre».
     * Se mira sólo antes del primer punto, que es donde va el rango.
     */
    private function closes(string $when, \DateTimeImmutable $today): ?\DateTimeImmutable
    {
        $range = preg_split('/\.\s/u', $when)[0] ?? '';
        if (!preg_match_all('/(\d{1,2})\s+de\s+([a-záéíóú]+)(?:\s+(?:de\s+)?(\d{4}))?/iu', $range, $m, \PREG_SET_ORDER)) {
            return null;
        }

        $last  = end($m);
        $month = SpanishDate::month($last[2]);
        if ($month === null) {
            return null;
        }
        if (!empty($last[3])) {
            return checkdate($month, (int) $last[1], (int) $last[3])
                ? $today->setDate((int) $last[3], $month, (int) $last[1])
                : null;
        }

        return SpanishDate::build((int) $last[1], $month, null, $today);
    }
}
