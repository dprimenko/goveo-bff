<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Creates a standalone DBAL connection to Supabase (read-only source for migrations).
 * Does NOT go through Symfony's Doctrine service to avoid polluting the main ORM.
 */
final class SupabaseConnectionFactory
{
    public function __construct(
        private readonly string $databaseUrl,
    ) {}

    public function create(): Connection
    {
        return DriverManager::getConnection([
            'url'           => $this->databaseUrl,
            'driver'        => 'pdo_pgsql',
            'serverVersion' => '15',
        ]);
    }
}
