<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

/**
 * Cardamomo, tablao de la calle Echegaray. Cada espectáculo tiene su ficha con un
 * evento estructurado por pase —dos o tres cada noche—, que `Shows` junta en uno.
 */
final class CardamomoSource extends JsonLdEventSource
{
    public function name(): string
    {
        return 'cardamomo';
    }

    protected function homeUrl(): string
    {
        return 'https://cardamomo.com/es/';
    }

    protected function pages(): iterable
    {
        // La portada enlaza los espectáculos en cartel; cada ficha lleva un
        // evento por pase.
        return $this->crawl('https://cardamomo.com/es/', '#^https://cardamomo\.com/es/show/[^/]+/$#', 20);
    }

    protected function venue(array $node): ?array
    {
        return [
            'name'     => 'Cardamomo',
            'lat'      => 40.4154556,
            'lng'      => -3.6995109,
            'address'  => 'Calle de Echegaray, 15, 28014 Madrid',
            'website'  => 'https://cardamomo.com/es/',
            'category' => 'culture-business',
        ];
    }

    protected function classify(array $node): array
    {
        return ['events-flamenco', 'events-flamenco-tablao'];
    }
}
