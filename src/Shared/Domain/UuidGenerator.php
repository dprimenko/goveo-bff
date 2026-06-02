<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Symfony\Component\Uid\Uuid;

final class UuidGenerator
{
    public static function generate(): string
    {
        return Uuid::v4()->toRfc4122();
    }
}
