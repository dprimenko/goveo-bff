<?php

declare(strict_types=1);

namespace App\Notifications\Domain;

interface UserNotificationDeviceRepository
{
    public function findById(string $id): ?UserNotificationDevice;
    public function findByDeviceId(string $deviceId): ?UserNotificationDevice;
    /** @return UserNotificationDevice[] */
    public function findByUserId(string $userId): array;
    public function save(UserNotificationDevice $device): void;
    public function delete(UserNotificationDevice $device): void;
}
