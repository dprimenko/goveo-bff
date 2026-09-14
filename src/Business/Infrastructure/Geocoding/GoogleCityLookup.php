<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Geocoding;

use App\Business\Domain\CityLookup;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * La ciudad, preguntándosela a la geocodificación inversa de Google.
 *
 * Se pregunta por **coordenadas y no por la dirección escrita**: la dirección es
 * el texto que devolvió Google el día del alta y viene en veinte formas
 * distintas —con provincia y sin ella, con código postal y sin él, en catalán o
 * en castellano—, así que partirla por comas acierta casi siempre. El punto del
 * mapa, en cambio, pertenece a un municipio y sólo a uno.
 *
 * `result_type` acota la respuesta a los niveles que son una ciudad, de más
 * concreto a menos: sin él, Google devuelve primero el portal exacto y hay que
 * ir bajando por los componentes de cada resultado.
 *
 * `administrative_area_level_2` está al final como red: en España es la
 * provincia, que no es una ciudad, pero para un punto en despoblado es lo único
 * que va a llegar y decir «Málaga» es mejor que no decir nada.
 */
final class GoogleCityLookup implements CityLookup
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    /** De más concreto a menos: la primera que aparezca es la que se guarda. */
    private const CITY_TYPES = ['locality', 'postal_town', 'administrative_area_level_2'];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
    ) {}

    public function cityAt(float $latitude, float $longitude): ?string
    {
        if ($this->apiKey === '') {
            // En local no hay clave y no pasa nada: la ciudad se queda vacía y
            // el panel enseña «Sin ciudad». Avisar en cada guardado sería ruido.
            return null;
        }

        try {
            $response = $this->http->request('GET', self::ENDPOINT, [
                'query' => [
                    'latlng'      => sprintf('%.7f,%.7f', $latitude, $longitude),
                    'result_type' => implode('|', self::CITY_TYPES),
                    // En español, no en el idioma del servidor: es lo que va a
                    // leer quien filtre en el panel.
                    'language'    => 'es',
                    'key'         => $this->apiKey,
                ],
                // Guardar una ficha no puede quedarse esperando a Google.
                'timeout' => 5,
            ]);

            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Geocodificación inversa fallida', ['error' => $e->getMessage()]);

            return null;
        }

        $status = (string) ($data['status'] ?? '');

        // ZERO_RESULTS es una respuesta, no un fallo: ese punto no cae en ningún
        // municipio. Lo demás —clave mal, cuota agotada— sí hay que contarlo, o
        // un relleno entero acabaría en blanco sin que nadie supiera por qué.
        if ($status !== 'OK') {
            if ($status !== 'ZERO_RESULTS') {
                $this->logger->warning('Google no devolvió la ciudad', [
                    'status' => $status,
                    'error'  => $data['error_message'] ?? null,
                ]);
            }

            return null;
        }

        foreach (self::CITY_TYPES as $type) {
            foreach ($data['results'] ?? [] as $result) {
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
