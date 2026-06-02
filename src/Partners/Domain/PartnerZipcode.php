<?php

declare(strict_types=1);

namespace App\Partners\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'partner_zipcodes')]
class PartnerZipcode
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'partner_id', type: 'guid')]
    private string $partnerId;

    #[ORM\Column(type: 'string', length: 20)]
    private string $zipcode;

    #[ORM\Column(name: 'deleted_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt;

    public function __construct(
        string $id,
        string $partnerId,
        string $zipcode,
    ) {
        $this->id = $id;
        $this->partnerId = $partnerId;
        $this->zipcode = $zipcode;
        $this->deletedAt = null;
    }

    public function getId(): string { return $this->id; }
    public function getPartnerId(): string { return $this->partnerId; }
    public function getZipcode(): string { return $this->zipcode; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }

    public function softDelete(): self
    {
        $this->deletedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isDeleted(): bool { return $this->deletedAt !== null; }
}
