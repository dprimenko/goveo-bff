<?php

declare(strict_types=1);

namespace App\Loyalty\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * La tarjeta de fidelización tal y como la ofrece un negocio: qué regala en cada
 * premio y si un admin se la ha dado a mano.
 *
 * Una fila por negocio, y sólo cuando alguien la ha configurado: un negocio sin
 * fila no tiene tarjeta, aunque su tarifa la incluya.
 *
 * Dos interruptores distintos, los dos como **la fecha en que se encendieron**, o
 * nula (así se sabe también desde cuándo):
 *
 * - `manually_enabled_at`: el panel le **da** la tarjeta a un negocio cuya
 *   tarifa no la incluye. **Sólo suma**: no quita la que da la tarifa.
 * - `activated_at`: el negocio la tiene **encendida**. Tener derecho a la
 *   tarjeta no es enseñarla: el negocio la enciende cuando ha terminado de
 *   configurar los premios, y la apaga si quiere pararla un tiempo (los sellos
 *   de sus clientes se guardan). El panel también puede tocarlo, por si el
 *   negocio se olvida.
 *
 * ⚠️ **Provisional: sale encendida** (`ACTIVE_BY_DEFAULT`). La app publicada no
 * tiene todavía el interruptor, y un negocio que configurase ahí su tarjeta la
 * dejaría apagada sin saberlo. Cuando la app nueva esté en las tiendas, se pone
 * a `false` y la tarjeta nueva saldrá apagada, que es lo que se quiere.
 */
#[ORM\Entity]
#[ORM\Table(name: 'loyalty_programs')]
class LoyaltyProgram
{
    /** Lo que cabe en la fila del premio sin partirse en la app. */
    public const REWARD_MAX_LENGTH = 80;

    /** La descripción se lee en la vista del premio, no en la tarjeta. */
    public const DESCRIPTION_MAX_LENGTH = 500;

    /** Ver la nota de la clase: `false` en cuanto la app con el interruptor esté publicada. */
    public const ACTIVE_BY_DEFAULT = true;

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

    #[ORM\Column(name: 'manually_enabled_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $manuallyEnabledAt;

    #[ORM\Column(name: 'activated_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $activatedAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $businessId)
    {
        $this->businessId      = $businessId;
        $this->rewards         = [];
        $this->manuallyEnabledAt = null;
        $this->activatedAt       = self::ACTIVE_BY_DEFAULT ? new \DateTimeImmutable() : null;
        $this->updatedAt         = new \DateTimeImmutable();
    }

    public function getBusinessId(): string          { return $this->businessId; }
    public function isManuallyEnabled(): bool        { return $this->manuallyEnabledAt !== null; }
    public function getManuallyEnabledAt(): ?\DateTimeImmutable { return $this->manuallyEnabledAt; }
    public function isActive(): bool                 { return $this->activatedAt !== null; }
    public function getActivatedAt(): ?\DateTimeImmutable { return $this->activatedAt; }
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

    /** Encenderla otra vez conserva la fecha de la primera: no ha cambiado nada. */
    public function setManuallyEnabled(bool $enabled): void
    {
        if ($enabled === $this->isManuallyEnabled()) {
            return;
        }
        $this->manuallyEnabledAt = $enabled ? new \DateTimeImmutable() : null;
        $this->updatedAt         = new \DateTimeImmutable();
    }

    /** Encender otra vez conserva la fecha: la tarjeta ya estaba encendida. */
    public function setActive(bool $active): void
    {
        if ($active === $this->isActive()) {
            return;
        }
        $this->activatedAt = $active ? new \DateTimeImmutable() : null;
        $this->updatedAt   = new \DateTimeImmutable();
    }
}
