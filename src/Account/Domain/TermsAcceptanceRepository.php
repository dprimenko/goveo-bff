<?php

declare(strict_types=1);

namespace App\Account\Domain;

interface TermsAcceptanceRepository
{
    /** La última versión que aceptó, o null si nunca aceptó ninguna. */
    public function latestVersion(string $userId): ?string;

    public function has(string $userId, string $version): bool;

    public function save(TermsAcceptance $acceptance): void;
}
