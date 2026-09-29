<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Geocoding;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * El código postal de un punto, por la geocodificación inversa de Google.
 *
 * Para los negocios cuya dirección no lo lleva escrito: la dirección es el
 * texto del día del alta, y a veces viene sin él. Como con la ciudad
 * (`GoogleCityLookup`), se pregunta por **coordenadas** y no por el texto: el
 * punto cae en un código y sólo en uno. Si ahí no sale —hay puntos para los
 * que Google no devuelve código—, se prueba con la dirección escrita.
 */
final class GooglePostalCodeLookup
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function postalCodeFor(?float $latitude, ?float $longitude, string $address = ''): ?string
    {
        if ($this->apiKey === '') {
            return null;
        }

        $code = $latitude !== null && $longitude !== null
            ? $this->ask(['latlng' => sprintf('%.7f,%.7f', $latitude, $longitude), 'result_type' => 'postal_code'])
            : null;

        return $code ?? (trim($address) !== '' ? $this->ask(['address' => $address]) : null);
    }

    /** @param array<string, string> $query */
    private function ask(array $query): ?string
    {
        try {
            $data = $this->http->request('GET', self::ENDPOINT, [
                'query'   => $query + ['key' => $this->apiKey],
                'timeout' => 5,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Geocodificación del código postal fallida', ['error' => $e->getMessage()]);

            return null;
        }

        $status = (string) ($data['status'] ?? '');
        if ($status !== 'OK') {
            if ($status !== 'ZERO_RESULTS') {
                $this->logger->warning('Google no devolvió el código postal', [
                    'status' => $status,
                    'error'  => $data['error_message'] ?? null,
                ]);
            }

            return null;
        }

        foreach ($data['results'] ?? [] as $result) {
            foreach ($result['address_components'] ?? [] as $component) {
                if (in_array('postal_code', $component['types'] ?? [], true)) {
                    $code = trim((string) ($component['long_name'] ?? ''));
                    if ($code !== '') {
                        return $code;
                    }
                }
            }
        }

        return null;
    }
}
