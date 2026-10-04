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

    /** Un segundo intento tras un corte de red o un 502/503/504, con esta pausa. */
    private const RETRY_DELAY = 5_000_000;

    /** @var array<string, float> */
    private array $lastHit = [];

    /** Por qué falló la última descarga (`HTTP 403`, el tiempo agotado…), o `null`. */
    private ?string $lastError = null;

    /** Si ese fallo puede ser pasajero (corte de red, 502/503/504) y merece otro intento. */
    private bool $transient = false;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    public function get(string $url, int $maxBytes = 5 * 1024 * 1024): ?string
    {
        $body = $this->fetch($url, $maxBytes);

        // Un fallo pasajero —la agenda de esMadrid o de la Red de Teatros
        // tirando de vez en cuando desde el servidor— no puede dejar fuera
        // una fuente entera hasta la pasada siguiente: se prueba otra vez.
        if ($body === null && $this->transient) {
            usleep(self::RETRY_DELAY);
            $body = $this->fetch($url, $maxBytes);
        }

        return $body;
    }

    /**
     * Por qué falló la última descarga. Las fuentes sólo dicen «no se pudo
     * leer»; con esto el comando dice también si fue un 403 —la web nos
     * bloquea— o un corte —volverá sola—.
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    private function fetch(string $url, int $maxBytes): ?string
    {
        $this->waitTurn($url);
        $this->lastError = null;
        $this->transient = false;

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers'       => ['User-Agent' => self::USER_AGENT, 'Accept-Language' => 'es-ES,es;q=0.9'],
                'timeout'       => 30,
                'max_redirects' => 5,
            ]);

            $status = $response->getStatusCode();
            if ($status !== 200) {
                $this->logger->info('event-scraping: {status} en {url}', ['status' => $status, 'url' => $url]);
                $this->lastError = sprintf('HTTP %d en %s', $status, $url);
                $this->transient = in_array($status, [502, 503, 504], true);

                return null;
            }

            $body = $response->getContent();
            if (strlen($body) > $maxBytes) {
                $this->lastError = sprintf('%s pesa %d MB, más del máximo', $url, intdiv(strlen($body), 1048576));

                return null;
            }

            return $body;
        } catch (\Throwable $e) {
            $this->logger->info('event-scraping: no se pudo descargar {url}: {error}', ['url' => $url, 'error' => $e->getMessage()]);
            $this->lastError = sprintf('%s (%s)', $e->getMessage(), $url);
            $this->transient = true;

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
