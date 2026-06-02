<?php

declare(strict_types=1);

namespace App\Notifications\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_notifications_devices')]
class UserNotificationDevice
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'user_id', type: 'guid', nullable: true)]
    private ?string $userId;

    #[ORM\Column(name: 'device_id', type: 'string', length: 255)]
    private string $deviceId;

    #[ORM\Column(name: 'device_info', type: 'text', nullable: true)]
    private ?string $deviceInfo;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $deviceId,
        ?string $userId = null,
        ?string $deviceInfo = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->id = $id;
        $this->deviceId = $deviceId;
        $this->userId = $userId;
        $this->deviceInfo = $deviceInfo;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getUserId(): ?string { return $this->userId; }
    public function getDeviceId(): string { return $this->deviceId; }
    public function getDeviceInfo(): ?string { return $this->deviceInfo; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setUserId(?string $userId): self
    {
        $this->userId = $userId;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setDeviceInfo(?string $deviceInfo): self
    {
        $this->deviceInfo = $deviceInfo;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
