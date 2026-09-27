<?php

declare(strict_types=1);

namespace App\Account\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Un usuario aceptó una versión de las condiciones de uso.
 *
 * Tabla propia y no una columna en `users`: quien entra con Google o Apple no
 * siempre tiene fila ahí (ver `LocalUserResolver`), y así además queda el
 * historial —qué versión aceptó y cuándo—, que es lo que se pide si alguien
 * reclama. `user_id` es el que da `LocalUserResolver`, sea cual sea.
 */
#[ORM\Entity]
#[ORM\Table(name: 'terms_acceptances')]
#[ORM\UniqueConstraint(name: 'uniq_terms_acceptances', columns: ['user_id', 'version'])]
class TermsAcceptance
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'user_id', type: 'string', length: 255)]
    private string $userId;

    /** La que decide la app, que es quien enseña el texto (p. ej. `2026-10`). */
    #[ORM\Column(type: 'string', length: 32)]
    private string $version;

    #[ORM\Column(name: 'accepted_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $acceptedAt;

    public function __construct(string $id, string $userId, string $version, ?\DateTimeImmutable $acceptedAt = null)
    {
        $this->id         = $id;
        $this->userId     = $userId;
        $this->version    = $version;
        $this->acceptedAt = $acceptedAt ?? new \DateTimeImmutable();
    }

    public function getId(): string                     { return $this->id; }
    public function getUserId(): string                 { return $this->userId; }
    public function getVersion(): string                { return $this->version; }
    public function getAcceptedAt(): \DateTimeImmutable { return $this->acceptedAt; }
}
