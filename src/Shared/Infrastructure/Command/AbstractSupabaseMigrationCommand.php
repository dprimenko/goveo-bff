<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Uid\Uuid;

/**
 * Utility base for Supabase → local migration commands.
 * Provides UUID v5 conversion, timestamp parsing, and JSON helpers.
 */
abstract class AbstractSupabaseMigrationCommand extends Command
{
    private const UUID_NS = '7e4d3c2a-1b5f-4e8d-9a6c-0f2e1d3b5a7c';

    private ?Uuid $ns = null;

    protected function ns(): Uuid
    {
        return $this->ns ??= Uuid::fromString(self::UUID_NS);
    }

    /** Convert any opaque string ID to a deterministic UUID v5. */
    protected function toUuid(string $sourceId): string
    {
        return Uuid::v5($this->ns(), $sourceId)->toRfc4122();
    }

    /** Normalise a nullable ISO timestamp string for PostgreSQL TIMESTAMP WITH TIME ZONE. */
    protected function ts(?string $ts): ?string
    {
        if ($ts === null || $ts === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($ts))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:sP');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Return JSON string or null, handling both PHP arrays and raw JSON strings. */
    protected function json(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (is_array($raw)) {
            return json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }
        if (is_string($raw) && $raw !== '') {
            return $raw;
        }
        return null;
    }

    /** Coerce any PostgreSQL boolean representation to PHP bool. */
    protected function bool(mixed $val): bool
    {
        if ($val === null || $val === false || $val === '' || $val === '0' || $val === 'f' || $val === 'false') {
            return false;
        }
        return (bool) $val;
    }
}
