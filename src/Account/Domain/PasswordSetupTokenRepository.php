<?php

declare(strict_types=1);

namespace App\Account\Domain;

interface PasswordSetupTokenRepository
{
    public function findByPlainToken(string $plain): ?PasswordSetupToken;

    public function save(PasswordSetupToken $token): void;
}
