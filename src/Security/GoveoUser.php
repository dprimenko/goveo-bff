<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

class GoveoUser implements UserInterface
{
    public function __construct(
        private readonly string $id,
        private readonly ?string $email,
        private readonly array $roles = [],
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function eraseCredentials(): void {}

    public function getUserIdentifier(): string
    {
        return $this->id;
    }
}
