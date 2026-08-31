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
        private readonly ?string $firstName = null,
        private readonly ?string $lastName = null,
        private readonly ?string $name = null,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function getName(): ?string
    {
        if ($this->name !== null && $this->name !== '') {
            return $this->name;
        }

        $full = trim(sprintf('%s %s', (string) $this->firstName, (string) $this->lastName));

        return $full !== '' ? $full : null;
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
