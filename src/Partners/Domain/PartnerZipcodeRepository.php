<?php

declare(strict_types=1);

namespace App\Partners\Domain;

interface PartnerZipcodeRepository
{
    public function findById(string $id): ?PartnerZipcode;
    /** @return PartnerZipcode[] */
    public function findByPartnerId(string $partnerId): array;
    public function save(PartnerZipcode $zipcode): void;
    public function delete(PartnerZipcode $zipcode): void;
}
