<?php

declare(strict_types=1);

namespace App\Loyalty\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * La tarjeta de fidelización tal y como la ofrece un negocio: qué regala en cada
 * premio y si un admin se la ha activado a mano.
 *
 * Una fila por negocio, y sólo cuando alguien la ha configurado: un negocio sin
 * fila no tiene tarjeta, aunque su tarifa la incluya.
 *
 * La activación manual **sólo suma**. Sirve para dársela a un negocio cuya
 * tarifa no la incluye; no quita la que da la tarifa.
 */
#[ORM\Entity]
#[ORM\Table(name: 'loyalty_programs')]
class LoyaltyProgram
{
    /** Lo que cabe en la fila del premio sin partirse en la app. */
    public const REWARD_MAX_LENGTH = 80;

    /** La descripción se lee en la vista del premio, no en la tarjeta. */
    public const DESCRIPTION_MAX_LENGTH = 500;

    #[ORM\Id]
    #[ORM\Column(name: 'business_id', type: 'guid')]
    private string $businessId;

    /**
     * Premio por sello: `{"3": {"label": "Café gratis", "description": "…"}}`.
     *
     * @var array<string, array{label: string, description: ?string}>
     */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $rewards;

    #[ORM\Column(name: 'manually_enabled', type: 'boolean', options: ['default' => false])]
    private bool $manuallyEnabled;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $businessId)
    {
        $this->businessId      = $businessId;
        $this->rewards         = [];
        $this->manuallyEnabled = false;
        $this->updatedAt       = new \DateTimeImmutable();
    }

    public function getBusinessId(): string          { return $this->businessId; }
    public function isManuallyEnabled(): bool        { return $this->manuallyEnabled; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** El nombre del premio de ese sello, o null si no da. */
    public function rewardFor(int $stage): ?string
    {
        return $this->rewards[(string) $stage]['label'] ?? null;
    }

    public function rewardDescription(int $stage): ?string
    {
        return $this->rewards[(string) $stage]['description'] ?? null;
    }

    /**
     * @return array<int, array{label: string, description: ?string}> sólo los
     *                                                                  que están puestos
     */
    public function rewards(): array
    {
        $rewards = [];
        foreach (LoyaltyCard::REWARD_STAGES as $stage) {
            $label = $this->rewardFor($stage);
            if ($label !== null) {
                $rewards[$stage] = ['label' => $label, 'description' => $this->rewardDescription($stage)];
            }
        }

        return $rewards;
    }

    /** Sin premios la tarjeta no tiene nada que ofrecer, y no se enseña. */
    public function hasRewards(): bool
    {
        return $this->rewards() !== [];
    }

    /**
     * Pone o quita el premio de un sello. Sin nombre lo quita, descripción
     * incluida: una descripción sola no es un premio.
     *
     * @throws \InvalidArgumentException si el sello no da premio
     */
    public function setReward(int $stage, ?string $label, ?string $description = null): void
    {
        if (!in_array($stage, LoyaltyCard::REWARD_STAGES, true)) {
            throw new \InvalidArgumentException(sprintf('El sello %d no da premio.', $stage));
        }

        $label       = $label === null ? '' : trim($label);
        $description = $description === null ? '' : trim($description);
        $rewards     = $this->rewards;

        if ($label === '') {
            unset($rewards[(string) $stage]);
        } else {
            $rewards[(string) $stage] = [
                'label'       => $label,
                'description' => $description === '' ? null : $description,
            ];
        }

        // Se reasigna el array entero: Doctrine compara el campo json por valor
        // y así detecta el cambio.
        $this->rewards   = $rewards;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setManuallyEnabled(bool $enabled): void
    {
        $this->manuallyEnabled = $enabled;
        $this->updatedAt       = new \DateTimeImmutable();
    }
}
