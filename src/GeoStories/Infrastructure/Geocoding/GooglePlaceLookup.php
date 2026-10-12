<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Geocoding;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Código postal y municipio de varios puntos a la vez, por la geocodificación
 * inversa de Google: «28901 Getafe», no «Madrid» para todo lo de la comunidad.
 *
 * Una sola llamada por punto saca las dos cosas (las de negocio,
 * `GooglePostalCodeLookup` y `GoogleCityLookup`, hacen una cada una), y las
 * llamadas salen **en paralelo**: lo pide el listado de vídeos del panel para
 * las tarjetas que tiene en pantalla, y en fila serían segundos.
 */
final class GooglePlaceLookup
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    /** Municipio, de más concreto a menos; la provincia, sólo como red en despoblado. */
    private const CITY_TYPES = ['locality', 'postal_town', 'administrative_area_level_3', 'administrative_area_level_2'];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @param array<string, array{lat: float, lng: float}> $points por id
     *
     * @return array<string, array{postal_code: ?string, city: ?string}> sólo los que Google contestó
     */
    public function placesFor(array $points): array
    {
        if ($this->apiKey === '' || $points === []) {
            return [];
        }

        // Se lanzan todas y luego se leen: el cliente las lleva a la vez.
        $responses = [];
        foreach ($points as $id => $point) {
            $responses[$id] = $this->http->request('GET', self::ENDPOINT, [
                'query'   => [
                    'latlng'   => sprintf('%.7f,%.7f', $point['lat'], $point['lng']),
                    'language' => 'es',
                    'key'      => $this->apiKey,
                ],
                'timeout' => 5,
            ]);
        }

        $places = [];
        foreach ($responses as $id => $response) {
            try {
                $data = $response->toArray(false);
            } catch (\Throwable $e) {
                $this->logger->warning('Geocodificación inversa fallida', ['error' => $e->getMessage()]);
                continue;
            }

            $status = (string) ($data['status'] ?? '');
            if ($status === 'ZERO_RESULTS') {
                // Una respuesta, no un fallo: se guarda vacía para no volver a preguntar.
                $places[$id] = ['postal_code' => null, 'city' => null];
                continue;
            }
            if ($status !== 'OK') {
                $this->logger->warning('Google no devolvió el lugar', ['status' => $status, 'error' => $data['error_message'] ?? null]);
                continue;
            }

            $places[$id] = [
                'postal_code' => self::component($data['results'] ?? [], ['postal_code']),
                'city'        => self::component($data['results'] ?? [], self::CITY_TYPES),
            ];
        }

        return $places;
    }

    /**
     * El primer componente de alguno de esos tipos, por orden de tipo: un
     * municipio antes que la provincia aunque ésta salga antes en la respuesta.
     *
     * @param list<array<string, mixed>> $results
     * @param list<string>               $types
     */
    private static function component(array $results, array $types): ?string
    {
        foreach ($types as $type) {
            foreach ($results as $result) {
                foreach ($result['address_components'] ?? [] as $component) {
                    if (in_array($type, $component['types'] ?? [], true)) {
                        $name = trim((string) ($component['long_name'] ?? ''));
                        if ($name !== '') {
                            return $name;
                        }
                    }
                }
            }
        }

        return null;
    }
}
