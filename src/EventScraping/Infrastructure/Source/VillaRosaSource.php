<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

/**
 * Tablao Flamenco 1911 (el antiguo Villa Rosa), en la plaza de Santa Ana. Como
 * Cardamomo: una ficha por espectáculo y un evento por pase.
 */
final class VillaRosaSource extends JsonLdEventSource
{
    public function name(): string
    {
        return 'villa-rosa';
    }

    protected function homeUrl(): string
    {
        return 'https://tablaoflamenco1911.com/es/';
    }

    protected function pages(): iterable
    {
        return $this->crawl(
            'https://tablaoflamenco1911.com/es/espectaculos/',
            '#^https://tablaoflamenco1911\.com/es/espectaculos/[^/]+/$#',
            20,
        );
    }

    protected function venue(array $node): ?array
    {
        return [
            'name'     => 'Tablao Flamenco 1911',
            'lat'      => 40.4149873,
            'lng'      => -3.7014214,
            'address'  => 'Plaza de Santa Ana, 15, 28012 Madrid',
            'website'  => 'https://tablaoflamenco1911.com/es/',
            'category' => 'culture-business',
        ];
    }

    protected function classify(array $node): array
    {
        return ['events-flamenco', 'events-flamenco-tablao'];
    }
}
