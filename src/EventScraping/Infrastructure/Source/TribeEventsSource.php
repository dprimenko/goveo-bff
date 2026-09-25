<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Base de las salas con WordPress y el plugin «The Events Calendar», que expone
 * su agenda en una API (`/wp-json/tribe/events/v1/events`): título, fechas,
 * imagen y enlace de entradas ya separados, sin tener que leer la maqueta.
 *
 * Una sala nueva de este tipo es una clase con su dirección, su sala y su tipo.
 */
abstract class TribeEventsSource implements EventSource
{
    private const PER_PAGE  = 50;
    private const MAX_PAGES = 6;

    public function __construct(protected readonly WebPage $web) {}

    public function city(): string
    {
        return 'Madrid';
    }

    /** La web, sin barra final: `https://salariviera.com`. */
    abstract protected function site(): string;

    /** @return array{name: string, lat: float, lng: float, address: string, category: string} */
    abstract protected function venue(): array;

    /**
     * Tipo y subnivel (slugs). Recibe las categorías que la sala le pone en su
     * web, que a veces dicen más que el título (Danza, Flamenco…).
     *
     * @param list<string> $categories nombres, en minúsculas
     *
     * @return array{0: ?string, 1: ?string}
     */
    abstract protected function classify(string $title, array $categories): array;

    public function fetch(): iterable
    {
        $tz    = new \DateTimeZone('Europe/Madrid');
        $venue = $this->venue();
        $today = (new \DateTimeImmutable('today', $tz))->format('Y-m-d');

        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $raw = $this->web->get(sprintf(
                '%s/wp-json/tribe/events/v1/events?start_date=%s&per_page=%d&page=%d',
                $this->site(),
                $today,
                self::PER_PAGE,
                $page,
            ));
            $data = $raw !== null ? json_decode($raw, true) : null;
            if (!is_array($data) || !is_array($data['events'] ?? null)) {
                if ($page === 1) {
                    throw new \RuntimeException(sprintf('No se pudo leer la agenda de %s', $this->site()));
                }
                break;
            }

            foreach ($data['events'] as $e) {
                $title = Html::clean($e['title'] ?? null, 200);
                $start = $this->date($e['start_date'] ?? null, $tz);
                $url   = is_string($e['url'] ?? null) ? $e['url'] : null;
                if ($title === null || $start === null || $url === null) {
                    continue;
                }

                $end = $this->date($e['end_date'] ?? null, $tz);
                if (($e['all_day'] ?? false) === true) {
                    $end = ($end ?? $start)->setTime(23, 59);
                } elseif ($end !== null && $end <= $start) {
                    $end = null;
                }

                $tickets    = is_string($e['website'] ?? null) && preg_match('#^https?://#', $e['website']) ? $e['website'] : null;
                $categories = array_map(
                    fn ($c) => mb_strtolower((string) ($c['name'] ?? '')),
                    is_array($e['categories'] ?? null) ? $e['categories'] : [],
                );
                [$subcategory, $subtype] = $this->classify(mb_strtolower($title), $categories);

                yield new ScrapedEvent(
                    source: $this->name(),
                    externalId: (string) ($e['id'] ?? basename(trim((string) parse_url($url, \PHP_URL_PATH), '/'))),
                    title: $title,
                    start: $start,
                    end: $end,
                    city: $this->city(),
                    venueName: $venue['name'],
                    latitude: $venue['lat'],
                    longitude: $venue['lng'],
                    link: $tickets ?? $url,
                    linkAction: $tickets !== null ? 'buy' : 'info',
                    description: Html::clean($e['excerpt'] ?? $e['description'] ?? null),
                    imageUrl: is_string($e['image']['url'] ?? null) ? $e['image']['url'] : null,
                    detailUrl: $url,
                    venueAddress: $venue['address'],
                    subcategory: $subcategory,
                    subtype: $subtype,
                );
            }

            if ($page >= (int) ($data['total_pages'] ?? 1)) {
                break;
            }
        }
    }

    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    public function venueFor(ScrapedEvent $event): ScrapedVenue
    {
        $venue = $this->venue();

        return new ScrapedVenue(
            source: $this->name(),
            externalId: 'venue',
            name: $venue['name'],
            city: $this->city(),
            categorySlug: $venue['category'],
            latitude: $venue['lat'],
            longitude: $venue['lng'],
            address: $venue['address'],
            website: $this->site() . '/',
        );
    }

    /** `2026-09-25 21:00:00`, en hora de Madrid. */
    private function date(mixed $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, $tz) ?: null;
    }
}
