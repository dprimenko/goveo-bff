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
 * Teatro Lara (teatrolara.com), en Malasaña: dos salas, la Cándido Lara y la
 * Lola Membrives, con varias obras a la vez.
 *
 * Su calendario en lista (`calendario-lara.php`) da cada función —día, hora de
 * inicio y fin, obra y sala—, doce por página y por orden. Se pasan páginas
 * hasta salir del horizonte. El mes sólo sale en el rótulo («Octubre 2026»)
 * que encabeza cada página y cada cambio de mes, así que se lee en orden.
 *
 * - La ficha de cada obra se abre una vez, en `fetch` y no en `enrich`: el tipo
 *   (teatro infantil, humor…) sale de su texto, y se fija al crear el evento.
 * - Entradas en su taquilla de Onebox (`entradas.teatrolara`), la del teatro.
 * - Una obra en las dos salas a la vez sería un evento por sala.
 */
final class TeatroLaraSource implements EventSource
{
    private const SITE    = 'https://teatrolara.com';
    private const LISTING = self::SITE . '/calendario-lara.php?page_no=%d';
    private const NAME    = 'Teatro Lara';
    private const LAT     = 40.4221091;
    private const LNG     = -3.7044668;
    private const ADDRESS = 'Corredera Baja de San Pablo, 15, 28004 Madrid';

    /** Hasta dónde se pasan páginas: el cron mira 30 días, con margen. */
    private const HORIZON_DAYS = 45;
    private const MAX_PAGES    = 40;

    private const MONTHS = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7,
        'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
    ];

    /** @var array<string, array{image: ?string, description: ?string, tickets: ?string, text: string}> */
    private array $plays = [];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'teatro-lara';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $tz      = new \DateTimeZone('Europe/Madrid');
        $horizon = (new \DateTimeImmutable('today', $tz))->modify(sprintf('+%d days', self::HORIZON_DAYS));
        $passes  = [];

        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $html = $this->web->get(sprintf(self::LISTING, $page));
            if ($html === null) {
                if ($page === 1) {
                    throw new \RuntimeException('No se pudo descargar el calendario del Teatro Lara');
                }
                break;
            }

            $xp    = Html::xpath($html);
            $month = null;
            $year  = null;
            $found = 0;
            $last  = null;

            // Rótulos de mes y funciones, en el orden de la página.
            foreach ($xp->query('//div[' . Html::hasClass('titulomes') . '] | //div[' . Html::hasClass('listaeventos') . ']') as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                if (str_contains($node->getAttribute('class'), 'titulomes')) {
                    if (preg_match('/(\p{L}+)\s+(\d{4})/u', mb_strtolower(trim($node->textContent)), $m) && isset(self::MONTHS[$m[1]])) {
                        $month = self::MONTHS[$m[1]];
                        $year  = (int) $m[2];
                    }
                    continue;
                }

                ++$found;
                $day   = (int) preg_replace('/\D/', '', (string) Html::text($xp, './/div[' . Html::hasClass('columna1') . ']/text()[normalize-space()]', $node));
                $link  = Html::attr($xp, './/h3/a', 'href', $node);
                $title = Html::clean(Html::text($xp, './/h3', $node), 200);
                $hours = (string) Html::text($xp, './/div[' . Html::hasClass('columna2') . ']//span', $node);
                if ($month === null || $year === null || $day === 0 || !checkdate($month, $day, $year) || $link === null || $title === null) {
                    continue;
                }

                $date  = (new \DateTimeImmutable('now', $tz))->setDate($year, $month, $day)->setTime(0, 0);
                $start = $date;
                $end   = $date->setTime(23, 59);
                if (preg_match('/(\d{1,2}):(\d{2})(?:\s*[–-]\s*(\d{1,2}):(\d{2}))?/u', $hours, $t)) {
                    $start = $date->setTime((int) $t[1], (int) $t[2]);
                    $end   = isset($t[3]) && $t[3] !== '' ? $date->setTime((int) $t[3], (int) $t[4]) : null;
                    if ($end !== null && $end <= $start) {
                        $end = null;
                    }
                }
                $last = $start;

                $play = $this->play($link);
                if ($play === null || $play['image'] === null) {
                    continue;
                }
                $room = Html::text($xp, './/div[' . Html::hasClass('nombre-sala') . ']', $node);
                [$subcategory, $subtype] = $this->classify(mb_strtolower($title . ' ' . $play['text']));

                $passes[] = new ScrapedEvent(
                    source: $this->name(),
                    externalId: $this->slug(basename((string) parse_url($link, \PHP_URL_PATH)) . ' ' . (string) $room),
                    title: $title,
                    start: $start,
                    end: $end,
                    city: $this->city(),
                    venueName: self::NAME,
                    latitude: self::LAT,
                    longitude: self::LNG,
                    link: $play['tickets'] ?? $link,
                    linkAction: $play['tickets'] !== null ? 'buy' : 'info',
                    description: $play['description'],
                    imageUrl: $play['image'],
                    detailUrl: $link,
                    venueAddress: self::ADDRESS,
                    subcategory: $subcategory,
                    subtype: $subtype,
                );
            }

            // Página vacía (se acabó el calendario) o ya fuera del horizonte.
            if ($found === 0 || ($last !== null && $last > $horizon)) {
                break;
            }
        }

        return Shows::group($passes);
    }

    /** La ficha ya se leyó en `fetch`. */
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
     * Cartel, sinopsis y compra de la ficha de la obra, una vez por obra.
     *
     * @return array{image: ?string, description: ?string, tickets: ?string, text: string}|null
     */
    private function play(string $url): ?array
    {
        if (array_key_exists($url, $this->plays)) {
            return $this->plays[$url];
        }

        $html = $this->web->get($url);
        if ($html === null) {
            return $this->plays[$url] = null;
        }

        $xp = Html::xpath($html);
        // La sinopsis es la columna ancha; la `meta` viene cortada a media letra.
        $synopsis = Html::text($xp, '(//div[@id="historia"]/following::div[' . Html::hasClass('col-sm-8') . '])[1]');
        $tickets  = Html::attr($xp, '//a[contains(@href, "oneboxtds.com") and contains(@href, "/events/")]', 'href');

        return $this->plays[$url] = [
            'image'       => Html::absolute(Html::attr($xp, '//meta[@property="og:image"]', 'content'), self::SITE),
            'description' => Html::clean($synopsis),
            'tickets'     => $tickets,
            'text'        => mb_strtolower((string) $synopsis),
        ];
    }

    /** @return array{0: string, 1: ?string} */
    private function classify(string $text): array
    {
        return match (true) {
            (bool) preg_match('/peque(ñ|n)os|niñ[oa]s|infantil|en familia|familiar/u', $text) => ['events-kids', 'events-kids-theater'],
            (bool) preg_match('/\bconcierto\b|\bgira\b/u', $text) => ['events-small-concerts', null],
            (bool) preg_match('/\bmusical\b/u', $text) => ['events-stage', 'events-stage-musicals'],
            (bool) preg_match('/stand.?up|mon[oó]logo|\bimpro/u', $text) => ['events-stage', 'events-stage-comedy'],
            (bool) preg_match('/\bmag(ia|o)\b|ilusionis/u', $text) => ['events-stage', 'events-stage-magic'],
            default => ['events-stage', 'events-stage-theater'],
        };
    }

    private function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return mb_substr(trim(preg_replace('/[^a-z0-9]+/', '-', $text) ?? '', '-'), 0, 200);
    }
}
