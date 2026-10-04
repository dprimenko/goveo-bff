<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Círculo de Bellas Artes (circulobellasartes.com): exposiciones, Cine Estudio,
 * conciertos y escénicas.
 *
 * Su agenda se pide **por día** (`?fecha_agenda=AAAA-MM-DD`) y devuelve las
 * tarjetas de lo que hay ese día —tipo, título, miniatura, rango—. Sin fecha
 * sólo da los próximos días, y los listados de cada sección no traen el cine ni
 * los conciertos sueltos: se pide día a día (`HORIZON` peticiones) y los días en
 * que sale cada cosa son sus días de pase. La API de WordPress no publica estos
 * tipos de entrada.
 *
 * - Sólo entran los tipos de la tarjeta de `fetch` que son un plan: fuera
 *   «Actividades» (conferencias, debates, entregas de premios), cursos y
 *   talleres de varios meses, noticias y los «Ciclos de cine» (cada película
 *   del ciclo sale también suelta).
 * - «Escénicas» con un rango de temporada (Jazz Círculo, Círculo de Cámara) es
 *   el abono: sus conciertos salen sueltos como «Eventos», así que se salta.
 * - La hora sale de la ficha («Horario: 20:30h»; en el cine, el primer pase de
 *   «Sesiones»). Las entradas son de su taquilla (reservaentradas.com).
 */
final class CirculoBellasArtesSource implements EventSource
{
    private const SITE    = 'https://www.circulobellasartes.com';
    private const AGENDA  = self::SITE . '/agenda/';
    private const NAME    = 'Círculo de Bellas Artes';
    private const LAT     = 40.4183042;
    private const LNG     = -3.6965333;
    private const ADDRESS = 'Calle de Alcalá, 42, 28014 Madrid';

    /** Días que se piden a la agenda: los 30 de la ventana del cron y hoy. */
    private const HORIZON = 62;

    /** «Escénicas» más largo que esto es un ciclo de temporada (ver arriba). */
    private const MAX_STAGE_DAYS = 14;

    /** Títulos de «Eventos» que son charlas y no conciertos. */
    private const TALKS = '/^(presentaci|conferencia|mesa redonda|debate|congreso|jornada|encuentro)/u';

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'circulo-bellas-artes';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $tz    = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $tz);

        /** @var array<string, array{card: array<string, mixed>, days: list<\DateTimeImmutable>}> $seen */
        $seen = [];

        for ($i = 0; $i < self::HORIZON; ++$i) {
            $day  = $today->modify(sprintf('+%d days', $i));
            $html = $this->web->get(self::AGENDA . '?' . http_build_query(['dia' => 'semana', 'fecha_agenda' => $day->format('Y-m-d'), 'categoria' => 'todas']));
            if ($html === null) {
                // Sin el primer día es que la web está caída; sin otro, es un
                // día menos.
                if ($i === 0) {
                    throw new \RuntimeException('No se pudo descargar la agenda del Círculo de Bellas Artes');
                }
                continue;
            }

            foreach ($this->cards($html) as $card) {
                $seen[$card['url']]['card'] ??= $card;
                $seen[$card['url']]['days'][] = $day;
            }
        }

        $events = [];
        foreach ($seen as $url => ['card' => $card, 'days' => $days]) {
            $type = $this->classify($card);
            if ($type === null) {
                continue;
            }

            [$from, $to] = $card['range'];
            $first = max($from, $days[0]);
            $last  = $to ?? $days[count($days) - 1];

            $events[] = new ScrapedEvent(
                source: $this->name(),
                externalId: $this->id($url),
                title: $card['title'],
                start: $first,
                end: $last->setTime(23, 59),
                city: $this->city(),
                venueName: self::NAME,
                latitude: self::LAT,
                longitude: self::LNG,
                link: $url,
                imageUrl: $card['image'],
                detailUrl: $url,
                // Los días en que la agenda lo da: los pases de una película,
                // los días que abre una exposición.
                weekdays: array_values(array_unique(array_map(fn (\DateTimeImmutable $d) => (int) $d->format('N'), $days))),
                venueAddress: self::ADDRESS,
                subcategory: $type[0],
                subtype: $type[1],
            );
        }

        return $events;
    }

    /**
     * Cartel, descripción, entradas y hora, de la ficha. Lo de «Escénicas» se
     * tipa aquí: el título no dice si es un concierto («The Sound of
     * Hollywood»), el texto sí.
     */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        if ($event->detailUrl === null || ($html = $this->web->get($event->detailUrl)) === null) {
            return $event;
        }

        $xp          = Html::xpath($html);
        $description = Html::clean(Html::attr($xp, '//meta[@property="og:description"]', 'content'));
        $tickets     = Html::attr($xp, '//a[contains(@href, "reservaentradas.com/evento/") or contains(@href, "reservaentradas.com/sesiones/")]', 'href');
        $text        = mb_strtolower(Html::text($xp, '//body') ?? '');

        [$subcategory, $subtype] = [$event->subcategory, $event->subtype];
        if ($subcategory === 'events-stage') {
            [$subcategory, $subtype] = match (true) {
                (bool) preg_match('/concierto|orquesta|sinfóni|recital|cuarteto|quartet|piano|jazz/u', $text) => ['events-small-concerts', null],
                (bool) preg_match('/\bdanza\b|ballet/u', $text)                                            => ['events-stage', 'events-stage-dance'],
                default                                                                                    => ['events-stage', null],
            };
        }

        [$start, $end] = $this->time($event, $text);

        return new ScrapedEvent(
            source: $event->source,
            externalId: $event->externalId,
            title: $event->title,
            start: $start,
            end: $end,
            city: $event->city,
            venueName: $event->venueName,
            latitude: $event->latitude,
            longitude: $event->longitude,
            link: $tickets ?? $event->link,
            linkAction: $tickets !== null ? 'buy' : 'info',
            description: $description ?? $event->description,
            imageUrl: Html::absolute(Html::attr($xp, '//meta[@property="og:image"]', 'content'), self::SITE) ?? $event->imageUrl,
            detailUrl: $event->detailUrl,
            weekdays: $event->weekdays,
            venueAddress: $event->venueAddress,
            subcategory: $subcategory,
            subtype: $subtype,
        );
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
     * Las tarjetas de un día de la agenda.
     *
     * @return list<array{url: string, kind: string, title: string, image: ?string, range: array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable}}>
     */
    private function cards(string $html): array
    {
        $xp    = Html::xpath($html);
        $cards = [];

        foreach ($xp->query('//div[' . Html::hasClass('agenda-container') . ']/div[' . Html::hasClass('carousel-item') . ']') as $node) {
            $url   = Html::attr($xp, './/h2//a', 'href', $node);
            $title = Html::clean(Html::text($xp, './/h2', $node), 200);
            $range = $this->range(Html::text($xp, './/*[' . Html::hasClass('carousel-item-fecha') . ']', $node));
            if ($url === null || $title === null || $range === null) {
                continue;
            }

            // La miniatura es un recorte de 530×355 («-530x355.jpg»): sin el
            // sufijo, el original. La ficha suele dar uno mejor (`enrich`).
            $thumb = Html::attr($xp, './/img', 'src', $node);

            $cards[] = [
                'url'   => Html::absolute($url, self::SITE) ?? $url,
                'kind'  => mb_strtolower(Html::text($xp, './/*[' . Html::hasClass('carousel-item-categoria') . ']', $node) ?? ''),
                'title' => $title,
                'image' => $thumb === null ? null : (string) preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $thumb),
                'range' => $range,
            ];
        }

        return $cards;
    }

    /**
     * Tipo de Goveo por el tipo de la tarjeta; nulo lo que no entra.
     *
     * @param array{url: string, kind: string, title: string, range: array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable}} $card
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function classify(array $card): ?array
    {
        $title = mb_strtolower($card['title']);

        switch ($card['kind']) {
            case 'exposiciones':
                return ['events-art', preg_match('/fotograf/u', $title) ? 'events-art-photography' : 'events-art-temporary'];

            case 'películas':
                return ['events-cinema', null];

            case 'eventos':
                return preg_match(self::TALKS, $title) ? null : ['events-small-concerts', null];

            case 'escénicas':
                [$from, $to] = $card['range'];
                if ($to !== null && $from->diff($to)->days > self::MAX_STAGE_DAYS) {
                    return null;
                }

                // Concierto o escena: lo decide el texto de la ficha (`enrich`).
                return ['events-stage', null];

            default:
                return null;
        }
    }

    /**
     * La hora, que el listado no da: «Horario: 20:30h» en un evento de un día,
     * el próximo pase de «Sesiones» («Jue 01/10, 21:15») en el cine.
     *
     * @return array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function time(ScrapedEvent $event, string $text): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid'));

        if ($event->subcategory === 'events-cinema') {
            preg_match_all('#\b(\d{1,2})/(\d{1,2}),\s*(\d{1,2}):(\d{2})#', $text, $sessions, \PREG_SET_ORDER);
            foreach ($sessions as $s) {
                $at = SpanishDate::build((int) $s[1], (int) $s[2], $s[3] . ':' . $s[4]);
                if ($at !== null && $at >= $now && $at >= $event->start->setTime(0, 0)) {
                    return [$at, $event->end];
                }
            }

            return [$event->start, $event->end];
        }

        $oneDay = $event->end !== null && $event->start->format('Y-m-d') === $event->end->format('Y-m-d');
        if ($oneDay && preg_match('/horario:\s*(\d{1,2})(?:[:.](\d{2}))?\s*h/u', $text, $m)) {
            return [$event->start->setTime((int) $m[1], (int) ($m[2] ?? 0)), null];
        }

        return [$event->start, $event->end];
    }

    /**
     * «20/09/2026 - 20/12/2026» o «05/10/2026».
     *
     * @return array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable}|null
     */
    private function range(?string $text): ?array
    {
        if ($text === null || !preg_match_all('#(\d{1,2})/(\d{1,2})/(\d{4})#', $text, $dates, \PREG_SET_ORDER)) {
            return null;
        }

        $tz  = new \DateTimeZone('Europe/Madrid');
        $out = [];
        foreach ($dates as $d) {
            if (!checkdate((int) $d[2], (int) $d[1], (int) $d[3])) {
                return null;
            }
            $out[] = (new \DateTimeImmutable('now', $tz))->setDate((int) $d[3], (int) $d[2], (int) $d[1])->setTime(0, 0);
        }

        return [$out[0], $out[1] ?? $out[0]];
    }

    /** La ruta de la ficha («ciclos-cine-peliculas-la-bola-negra»). */
    private function id(string $url): string
    {
        return mb_substr(str_replace('/', '-', trim((string) parse_url($url, \PHP_URL_PATH), '/')), 0, 200);
    }
}
