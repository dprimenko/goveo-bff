<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Stripe;

use Stripe\StripeClient;

final class StripeClientFactory
{
    private ?StripeClient $client = null;

    public function __construct(
        private readonly string $secretKey,
    ) {}

    public function create(): StripeClient
    {
        if ($this->secretKey === '') {
            throw new \RuntimeException('STRIPE_SECRET_KEY no está configurada.');
        }

        return $this->client ??= new StripeClient([
            'api_key'             => $this->secretKey,
            // Sin `stripe_version`: se usa la versión por defecto de la cuenta.
            // Fijarla aquí obliga a mantenerla a mano y a probar cada subida.
            'max_network_retries' => 2,
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }
}
