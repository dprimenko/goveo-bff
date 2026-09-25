<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

/**
 * Gruposmedia (gruposmedia.com): la cartelera de sus seis teatros de Madrid —
 * Gran Vía, Pequeño Gran Vía, Capitol Gran Vía, Alcázar, Maravillas y Fígaro—.
 * Cada espectáculo tiene su ficha con un evento estructurado por función, y la
 * función dice en qué teatro es.
 *
 * Son ~100 fichas por pasada: la más lenta de las salas, por la pausa entre
 * petición y petición (ver `WebPage`).
 *
 * Un teatro que no esté aquí se descarta: sin coordenadas no se sabría dónde
 * enseñarlo. Si aparece uno nuevo, se añade a `VENUES`.
 */
final class GruposmediaSource extends JsonLdEventSource
{
    /** @var array<string, array{0: float, 1: float, 2: string}> nombre => [lat, lng, dirección] */
    private const VENUES = [
        'Teatro Gran Vía'         => [40.4223165, -3.7088295, 'Gran Vía, 66, 28013 Madrid'],
        'Pequeño Teatro Gran Vía' => [40.4223165, -3.7088295, 'Gran Vía, 66, 28013 Madrid'],
        'Teatro Capitol Gran Vía' => [40.4204465, -3.7065821, 'Gran Vía, 41, 28013 Madrid'],
        'Teatro Alcázar'          => [40.4177414, -3.6990771, 'Calle de Alcalá, 20, 28014 Madrid'],
        'Teatro Maravillas'       => [40.4287637, -3.7030711, 'Calle de Manuela Malasaña, 6, 28004 Madrid'],
        'Teatro Fígaro'           => [40.4134714, -3.7035975, 'Calle del Doctor Cortezo, 5, 28012 Madrid'],
    ];

    public function name(): string
    {
        return 'gruposmedia';
    }

    protected function homeUrl(): string
    {
        return 'https://gruposmedia.com/';
    }

    protected function pages(): iterable
    {
        return $this->crawl('https://gruposmedia.com/cartelera/', '#^https://gruposmedia\.com/cartelera/[^/]+/$#');
    }

    protected function venue(array $node): ?array
    {
        $location = $node['location'] ?? null;
        $name     = is_array($location) ? trim((string) ($location['name'] ?? '')) : '';

        return $this->venueByName($name);
    }

    protected function venueByName(string $name): ?array
    {
        if (!isset(self::VENUES[$name])) {
            return null;
        }
        [$lat, $lng, $address] = self::VENUES[$name];

        return [
            'name'     => $name,
            'lat'      => $lat,
            'lng'      => $lng,
            'address'  => $address,
            'website'  => 'https://gruposmedia.com/',
            'category' => 'culture-business',
        ];
    }

    /** Escena, y el subnivel por el título: el dato no dice si es musical o comedia. */
    protected function classify(array $node): array
    {
        $title = mb_strtolower((string) ($node['name'] ?? ''));

        $subtype = match (true) {
            str_contains($title, 'musical')                                        => 'events-stage-musicals',
            (bool) preg_match('/\b(magia|mago|ilusionis)/u', $title)               => 'events-stage-magic',
            (bool) preg_match('/\b(mon[oó]logo|humor|comedia|stand.?up|c[oó]mic)/u', $title) => 'events-stage-comedy',
            default                                                                => 'events-stage-theater',
        };

        return ['events-stage', $subtype];
    }
}
