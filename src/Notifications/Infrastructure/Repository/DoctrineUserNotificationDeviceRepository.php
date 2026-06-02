<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Repository;

use App\Notifications\Domain\UserNotificationDevice;
use App\Notifications\Domain\UserNotificationDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineUserNotificationDeviceRepository implements UserNotificationDeviceRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?UserNotificationDevice
    {
        return $this->em->find(UserNotificationDevice::class, $id);
    }

    public function findByDeviceId(string $deviceId): ?UserNotificationDevice
    {
        return $this->em->getRepository(UserNotificationDevice::class)->findOneBy(['deviceId' => $deviceId]);
    }

    public function findByUserId(string $userId): array
    {
        return $this->em->getRepository(UserNotificationDevice::class)->findBy(['userId' => $userId]);
    }

    public function save(UserNotificationDevice $device): void
    {
        $this->em->persist($device);
        $this->em->flush();
    }

    public function delete(UserNotificationDevice $device): void
    {
        $this->em->remove($device);
        $this->em->flush();
    }
}
