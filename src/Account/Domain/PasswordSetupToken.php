<?php

declare(strict_types=1);

namespace App\Account\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Token de un solo uso para crear la contraseña inicial.
 *
 * El alta desde la web crea la cuenta sin contraseña: el dueño la define después
 * desde el correo de bienvenida.
 *
 * En base sólo vive el **hash**. Guardar el token en claro sería guardar una
 * llave: quien leyera la tabla podría entrar en cualquier cuenta recién creada.
 */
#[ORM\Entity]
#[ORM\Table(name: 'password_setup_tokens')]
class PasswordSetupToken
{
    /** Una semana: suficiente para quien no mira el correo a diario. */
    public const TTL = '+7 days';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'user_id', type: 'guid')]
    private string $userId;

    #[ORM\Column(name: 'token_hash', type: 'string', length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'used_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    private function __construct(string $id, string $userId, string $tokenHash)
    {
        $this->id        = $id;
        $this->userId    = $userId;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = (new \DateTimeImmutable())->modify(self::TTL);
        $this->usedAt    = null;
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Crea el token y devuelve **el valor en claro**, que sólo existe aquí y en
     * el correo: la base guarda su hash.
     *
     * @return array{token: self, plain: string}
     */
    public static function issue(string $id, string $userId): array
    {
        $plain = bin2hex(random_bytes(32));

        return ['token' => new self($id, $userId, self::hash($plain)), 'plain' => $plain];
    }

    public static function hash(string $plain): string
    {
        // SHA-256 y no bcrypt: el token ya es aleatorio de 256 bits, así que no
        // hay nada que ralentizar contra fuerza bruta, y hace falta buscarlo por
        // igualdad en la tabla.
        return hash('sha256', $plain);
    }

    public function getId(): string     { return $this->id; }
    public function getUserId(): string { return $this->userId; }

    public function isUsable(): bool
    {
        return $this->usedAt === null && $this->expiresAt > new \DateTimeImmutable();
    }

    public function markUsed(): void
    {
        $this->usedAt = new \DateTimeImmutable();
    }
}
