<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

/**
 * IFEMA: el calendario de ferias. Trae fechas y enlace pero no imagen, que se
 * saca de la ficha de cada feria (`og:image`, ver `enrich`).
 *
 * Mezcla ferias abiertas al público con profesionales, y el calendario no las
 * distingue: se descartan en el panel al validar.
 */
final class IfemaSource extends JsonLdEventSource
{
    public function name(): string
    {
        return 'ifema';
    }

    protected function homeUrl(): string
    {
        return 'https://www.ifema.es/';
    }

    protected function pages(): iterable
    {
        $html = $this->web->get('https://www.ifema.es/calendario/todos');
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar el calendario de IFEMA');
        }

        return [$html];
    }

    protected function venue(array $node): ?array
    {
        return [
            'name'     => 'IFEMA',
            'lat'      => 40.4654896,
            'lng'      => -3.6167070,
            'address'  => 'Avenida del Partenón, 5, 28042 Madrid',
            'website'  => 'https://www.ifema.es/',
            'category' => 'culture-business',
        ];
    }

    protected function classify(array $node): array
    {
        return ['events-markets', 'events-markets-fairs'];
    }
}
