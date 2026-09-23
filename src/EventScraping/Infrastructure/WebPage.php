<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Descarga páginas ajenas con buenos modales.
 *
 * Se identifica como Goveo —no se hace pasar por un navegador— y espera un poco
 * entre petición y petición a la misma web: el cron abre decenas de fichas de
 * golpe, y a una sala pequeña eso le puede parecer un ataque.
 *
 * Un fallo devuelve `null` en vez de lanzar: una ficha caída es un evento
 * menos, no motivo para cortar la pasada entera.
 */
final class WebPage
{
    private const USER_AGENT = 'Mozilla/5.0 (compatible; GoveoAgenda/1.0; +https://goveo.app)';

    /** Pausa entre peticiones al mismo dominio, en microsegundos. */
    private const POLITE_DELAY = 400_000;

    /** @var array<string, float> */
    private array $lastHit = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    public function get(string $url, int $maxBytes = 5 * 1024 * 1024): ?string
    {
        $this->waitTurn($url);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers'       => ['User-Agent' => self::USER_AGENT, 'Accept-Language' => 'es-ES,es;q=0.9'],
                'timeout'       => 30,
                'max_redirects' => 5,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->info('event-scraping: {status} en {url}', ['status' => $response->getStatusCode(), 'url' => $url]);

                return null;
            }

            $body = $response->getContent();

            return strlen($body) > $maxBytes ? null : $body;
        } catch (\Throwable $e) {
            $this->logger->info('event-scraping: no se pudo descargar {url}: {error}', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function waitTurn(string $url): void
    {
        $host = (string) parse_url($url, \PHP_URL_HOST);
        $last = $this->lastHit[$host] ?? null;

        if ($last !== null) {
            $elapsed = (microtime(true) - $last) * 1_000_000;
            if ($elapsed < self::POLITE_DELAY) {
                usleep((int) (self::POLITE_DELAY - $elapsed));
            }
        }

        $this->lastHit[$host] = microtime(true);
    }
}
