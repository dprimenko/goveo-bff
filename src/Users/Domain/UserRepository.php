<?php

declare(strict_types=1);

namespace App\Users\Domain;

interface UserRepository
{
    public function findById(string $id): ?User;
    public function findByEmail(string $email): ?User;
    public function save(User $user): void;
    public function delete(User $user): void;
}
