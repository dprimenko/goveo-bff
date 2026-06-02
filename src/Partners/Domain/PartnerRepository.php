<?php

declare(strict_types=1);

namespace App\Partners\Domain;

interface PartnerRepository
{
    public function findById(string $id): ?Partner;

    /** @return Partner[] */
    public function findAll(): array;

    public function save(Partner $partner): void;
    public function delete(Partner $partner): void;
}
