<?php

declare(strict_types=1);

namespace App\Loyalty\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * El QR que enseña el negocio: un sello o un canje, de un solo uso.
 *
 * Como en `PasswordSetupToken`, en base sólo vive el **hash**: el valor en claro
 * está en el QR y en ningún otro sitio.
 *
 * Caduca a los **30 minutos** y no a los pocos segundos: quien no tiene la app
 * escanea, la instala, se registra y el sello se tiene que poder aplicar al
 * llegar. Ser de un solo uso ya impide repartirlo.
 */
#[ORM\Entity]
#[ORM\Table(name: 'loyalty_tokens')]
#[ORM\Index(name: 'idx_loyalty_tokens_business', columns: ['business_id'])]
#[ORM\UniqueConstraint(name: 'uniq_loyalty_token', columns: ['token_hash'])]
class LoyaltyToken
{
    public const TTL = '+30 minutes';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'business_id', type: 'guid')]
    private string $businessId;

    #[ORM\Column(type: 'string', length: 10, enumType: LoyaltyTokenKind::class)]
    private LoyaltyTokenKind $kind;

    /** El premio que se canjea; sólo en los de canje. */
    #[ORM\Column(name: 'reward_stage', type: 'smallint', nullable: true)]
    private ?int $rewardStage;

    #[ORM\Column(name: 'token_hash', type: 'string', length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(name: 'created_by_user_id', type: 'guid')]
    private string $createdByUserId;

    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'used_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt;

    #[ORM\Column(name: 'used_by_user_id', type: 'guid', nullable: true)]
    private ?string $usedByUserId;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        string $id,
        string $businessId,
        LoyaltyTokenKind $kind,
        ?int $rewardStage,
        string $tokenHash,
        string $createdByUserId,
        \DateTimeImmutable $now,
    ) {
        $this->id              = $id;
        $this->businessId      = $businessId;
        $this->kind            = $kind;
        $this->rewardStage     = $rewardStage;
        $this->tokenHash       = $tokenHash;
        $this->createdByUserId = $createdByUserId;
        $this->expiresAt       = $now->modify(self::TTL);
        $this->usedAt          = null;
        $this->usedByUserId    = null;
        $this->createdAt       = $now;
    }

    /**
     * Crea el QR y devuelve **el valor en claro**, que sólo existe aquí.
     *
     * @return array{token: self, plain: string}
     *
     * @throws \InvalidArgumentException si un canje no dice qué premio, o uno de sello lo dice
     */
    public static function issue(
        string $id,
        string $businessId,
        LoyaltyTokenKind $kind,
        ?int $rewardStage,
        string $createdByUserId,
        ?\DateTimeImmutable $now = null,
    ): array {
        if ($kind === LoyaltyTokenKind::Redeem && !in_array($rewardStage, LoyaltyCard::REWARD_STAGES, true)) {
            throw new \InvalidArgumentException('Un canje necesita el sello del premio.');
        }
        if ($kind === LoyaltyTokenKind::Stamp && $rewardStage !== null) {
            throw new \InvalidArgumentException('Un sello no canjea premio.');
        }

        // 128 bits y no 256: el QR tiene que leerse bien en una pantalla de
        // móvil, y cuanto más largo el contenido, más denso el código. Con un
        // solo uso y media hora de vida, adivinarlo sigue siendo imposible.
        $plain = bin2hex(random_bytes(16));

        return [
            'token' => new self(
                $id,
                $businessId,
                $kind,
                $rewardStage,
                self::hash($plain),
                $createdByUserId,
                $now ?? new \DateTimeImmutable(),
            ),
            'plain' => $plain,
        ];
    }

    public static function hash(string $plain): string
    {
        // SHA-256 y no bcrypt: el token ya es aleatorio, y hay que buscarlo por
        // igualdad.
        return hash('sha256', $plain);
    }

    public function getId(): string                    { return $this->id; }
    public function getBusinessId(): string            { return $this->businessId; }
    public function getKind(): LoyaltyTokenKind        { return $this->kind; }
    public function getRewardStage(): ?int             { return $this->rewardStage; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getUsedAt(): ?\DateTimeImmutable   { return $this->usedAt; }
    public function getUsedByUserId(): ?string         { return $this->usedByUserId; }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    public function markUsed(string $userId, ?\DateTimeImmutable $now = null): void
    {
        $this->usedAt       = $now ?? new \DateTimeImmutable();
        $this->usedByUserId = $userId;
    }
}
