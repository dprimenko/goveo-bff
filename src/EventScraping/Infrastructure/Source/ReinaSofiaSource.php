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
 * Museo Reina Sofía: exposiciones temporales y actividades públicas.
 *
 * La web es un Gatsby montado sobre Drupal, y cada página tiene al lado su
 * `page-data.json` con los datos con los que se pinta: fechas en timestamp,
 * imagen, sala y categorías. Se lee eso y no la maqueta, que son clases
 * generadas (`SnippetMedium-module--title--5-nxM`) que cambian en cada build.
 *
 * - **Exposiciones**: el listado no dice dónde está cada una, y varias son en
 *   el Retiro (Palacio de Cristal, Palacio de Velázquez), que es otro sitio en
 *   el mapa. Se abre el `page-data` de cada una (son menos de diez).
 * - **Actividades**: cada una trae todos sus pases (`processedDates`, en hora de
 *   Madrid); los de una misma actividad salen como un evento con su rango
 *   (`Shows`). Se quedan fuera los seminarios y la investigación, lo escolar y
 *   los programas anuales con inscripción (ver `wanted`).
 */
final class ReinaSofiaSource implements EventSource
{
    private const SITE = 'https://www.museoreinasofia.es';

    /**
     * Las imágenes originales pesan hasta 6 MB; `large_portrait` es la misma
     * foto reducida a 1440 px de alto, sin recortar.
     */
    private const IMAGE = 'https://recursos.museoreinasofia.es/styles/large_portrait/public%s.webp';

    private const VENUES = [
        'main' => [
            'name'    => 'Museo Reina Sofía',
            'lat'     => 40.4079123,
            'lng'     => -3.6945569,
            'address' => 'Calle de Santa Isabel, 52, 28012 Madrid',
        ],
        'cristal' => [
            'name'    => 'Palacio de Cristal',
            'lat'     => 40.4136352,
            'lng'     => -3.6819997,
            'address' => 'Paseo de Cuba, 4, Parque del Retiro, 28009 Madrid',
        ],
        'velazquez' => [
            'name'    => 'Palacio de Velázquez',
            'lat'     => 40.4150379,
            'lng'     => -3.6819055,
            'address' => 'Paseo de Venezuela, 2, Parque del Retiro, 28001 Madrid',
        ],
    ];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'reina-sofia';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        return Shows::group([...$this->exhibitions(), ...$this->activities()]);
    }

    /** El `page-data.json` lo trae todo; no hay ficha que abrir. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        $key   = $this->venueKey($event->venueName);
        $venue = self::VENUES[$key];

        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue-' . $key,
            name: $venue['name'],
            city: $this->city(),
            categorySlug: 'culture-business',
            latitude: $venue['lat'],
            longitude: $venue['lng'],
            address: $venue['address'],
            website: self::SITE . '/',
        );
    }

    /** @return iterable<ScrapedEvent> */
    private function exhibitions(): iterable
    {
        $tz = new \DateTimeZone('Europe/Madrid');

        foreach ($this->paragraphs('/exposiciones', 'ParagraphExhibitionsList') as $list) {
            $items = [$list['featuredExhibition']['entity'] ?? null, ...($list['exhibitions']['data'] ?? [])];

            foreach ($items as $item) {
                $path  = $item['url']['path'] ?? null;
                $title = Html::clean($item['title']['value'] ?? null, 200);
                $from  = $item['dates']['valueTimestamp'] ?? null;
                $to    = $item['dates']['endValueTimestamp'] ?? null;
                if (!is_string($path) || $title === null || !is_int($from)) {
                    continue;
                }

                // La ficha dice la sala, la descripción y si hay entrada que comprar.
                $page     = $this->pageData($path) ?? [];
                $subtitle = Html::clean($item['subtitle']['value'] ?? null, 200);
                $about    = Html::clean($page['description']['value'] ?? null);
                $tickets  = $this->tickets($page['tickets'] ?? []);
                $start    = (new \DateTimeImmutable('@' . $from))->setTimezone($tz)->setTime(0, 0);
                $end      = is_int($to) ? (new \DateTimeImmutable('@' . $to))->setTimezone($tz)->setTime(23, 59) : $start->setTime(23, 59);
                $venue    = self::VENUES[$this->venueKey((string) ($page['location']['entity']['name'] ?? ''))];
                // El tipo, por lo que dice la propia exposición al presentarse.
                $about300 = mb_strtolower($title . ' ' . $subtitle . ' ' . mb_substr((string) $about, 0, 300));

                yield new ScrapedEvent(
                    source: $this->name(),
                    externalId: 'expo-' . ($item['id'] ?? basename($path)),
                    title: $subtitle !== null ? $title . '. ' . $subtitle : $title,
                    start: $start,
                    end: $end,
                    city: $this->city(),
                    venueName: $venue['name'],
                    latitude: $venue['lat'],
                    longitude: $venue['lng'],
                    link: $tickets ?? self::SITE . $path,
                    linkAction: $tickets !== null ? 'buy' : 'info',
                    description: $about,
                    imageUrl: $this->image($item),
                    detailUrl: self::SITE . $path,
                    venueAddress: $venue['address'],
                    subcategory: 'events-art',
                    subtype: match (true) {
                        (bool) preg_match('/inmersiv/u', $about300)          => 'events-art-immersive',
                        (bool) preg_match('/fotograf|fotógraf/u', $about300) => 'events-art-photography',
                        default                                             => 'events-art-temporary',
                    },
                );
            }
        }
    }

    /** @return iterable<ScrapedEvent> un pase por fecha; `Shows` los junta */
    private function activities(): iterable
    {
        $tz = new \DateTimeZone('Europe/Madrid');

        foreach ($this->paragraphs('/actividades', 'ParagraphActivitiesList') as $list) {
            foreach ($list['activities']['data'] ?? [] as $item) {
                $path  = $item['url']['path'] ?? null;
                $title = Html::clean($item['title']['value'] ?? null, 200);
                $about = Html::clean($item['description']['value'] ?? null);
                $categories = array_map(
                    fn ($c) => mb_strtolower((string) ($c['entity']['name'] ?? '')),
                    is_array($item['categories'] ?? null) ? $item['categories'] : [],
                );
                // Entero y no el resumen: a quién va dirigido, o que es un
                // concierto, suele estar hacia el final.
                $whole = strip_tags(($item['subtitle']['value'] ?? '') . ' ' . ($item['description']['value'] ?? ''));
                $type  = $this->classify($categories, mb_strtolower($title . ' ' . $whole));
                if (!is_string($path) || $title === null || !$this->wanted($title, $whole) || $type === null) {
                    continue;
                }

                $venue    = self::VENUES[$this->venueKey((string) ($item['events'][0]['entity']['location']['entity']['name'] ?? ''))];
                $duration = is_int($item['duration'] ?? null) ? $item['duration'] : null;

                foreach ($item['processedDates'] ?? [] as $date) {
                    $start = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', (string) ($date['value'] ?? ''), $tz);
                    if ($start === false) {
                        continue;
                    }

                    yield new ScrapedEvent(
                        source: $this->name(),
                        // La actividad, no el pase: todos comparten id.
                        externalId: 'act-' . ($item['id'] ?? basename($path)),
                        title: $title,
                        start: $start,
                        end: $duration !== null ? $start->modify("+{$duration} minutes") : null,
                        city: $this->city(),
                        venueName: $venue['name'],
                        latitude: $venue['lat'],
                        longitude: $venue['lng'],
                        link: self::SITE . $path,
                        linkAction: 'info',
                        description: $about,
                        imageUrl: $this->image($item),
                        detailUrl: self::SITE . $path,
                        venueAddress: $venue['address'],
                        subcategory: $type[0],
                        subtype: $type[1],
                    );
                }
            }
        }
    }

    /**
     * Lo que no es un plan para cualquiera: las visitas-taller para colegios,
     * los `equipo…` —grupos de un curso entero con inscripción previa— y la
     * versión en inglés de una visita que ya sale en español («Dear Felix:»).
     */
    private function wanted(string $title, string $text): bool
    {
        return !preg_match('/^equipo/iu', $title)
            && !preg_match('/alumnado|grupos escolares|centros educativos|grupos de educación|público general en inglés/iu', $text);
    }

    /**
     * Tipo y subnivel por las categorías del museo. Seminarios, conferencias e
     * investigación no entran: son del programa académico, no un plan. Lo que
     * no tiene categoría (recorridos para grupos concertados) tampoco.
     *
     * @param list<string> $categories
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function classify(array $categories, string $text): ?array
    {
        if (array_intersect($categories, ['seminarios y conferencias', 'investigación']) !== []) {
            return null;
        }

        return match (true) {
            in_array('cine y vídeo', $categories, true)     => ['events-experiences', 'events-experiences-cinema'],
            in_array('taller', $categories, true)           => ['events-experiences', 'events-experiences-workshops'],
            in_array('visita comentada', $categories, true) => ['events-experiences', 'events-experiences-guided-tours'],
            // «Artes en vivo» es casi siempre un concierto (Ana Curra, Los
            // Planetas); lo demás, performance, se queda en arte.
            in_array('artes en vivo', $categories, true)    => preg_match('/concierto|música|musical|canci|banda|dj\b/u', $text)
                ? ['events-small-concerts', null]
                : ['events-art', null],
            default                                         => null,
        };
    }

    /** @return iterable<array<string, mixed>> los párrafos de ese tipo de la página */
    private function paragraphs(string $path, string $type): iterable
    {
        $content = $this->pageData($path);
        if ($content === null) {
            throw new \RuntimeException(sprintf('No se pudo leer %s del Reina Sofía', $path));
        }

        foreach ($content['paragraphs'] ?? [] as $p) {
            if (($p['entity']['type'] ?? null) === $type) {
                yield $p['entity'];
            }
        }
    }

    /** @return array<string, mixed>|null el nodo de Drupal de esa página */
    private function pageData(string $path): ?array
    {
        $raw  = $this->web->get(self::SITE . '/page-data' . rtrim($path, '/') . '/page-data.json');
        $data = $raw !== null ? json_decode($raw, true) : null;
        $node = $data['result']['pageContext']['node']['data']['content'] ?? null;

        return is_array($node) ? $node : null;
    }

    /** @param array<string, mixed> $item */
    private function image(array $item): ?string
    {
        $src = $item['mainMedia']['entity']['image']['originalSrc'] ?? null;

        return is_string($src) && $src !== '' ? sprintf(self::IMAGE, $src) : null;
    }

    /**
     * El enlace de entradas de la exposición, si tiene: las gratuitas lo dejan
     * vacío.
     *
     * @param list<array<string, mixed>> $tickets
     */
    private function tickets(array $tickets): ?string
    {
        foreach ($tickets as $t) {
            $url = $t['entity']['url']['url']['path'] ?? null;
            if (is_string($url) && preg_match('#^https?://#', $url)) {
                return $url;
            }
        }

        return null;
    }

    /** Qué sede es, por el nombre de la sala que da el museo. */
    private function venueKey(string $location): string
    {
        $location = mb_strtolower($location);

        return match (true) {
            str_contains($location, 'cristal')   => 'cristal',
            str_contains($location, 'velázquez') => 'velazquez',
            default                              => 'main',
        };
    }
}
