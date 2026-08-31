<?php

declare(strict_types=1);

namespace App\GeoStories\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Un usuario ha dado like a una geostory.
 *
 * El contador `geostories.likes` es el número **heredado** de la app Flutter
 * (lo trajo el import de Supabase); esta tabla registra los likes nuevos, con
 * usuario, para poder saber si ya le diste like y poder quitarlo.
 *
 * `user_id` es el id local (`users.id`), no el `sub` del JWT.
 */
#[ORM\Entity]
#[ORM\Table(name: 'geostory_likes')]
#[ORM\UniqueConstraint(name: 'uniq_geostory_likes', columns: ['user_id', 'geostory_id'])]
#[ORM\Index(name: 'idx_geostory_likes_geostory', columns: ['geostory_id'])]
#[ORM\Index(name: 'idx_geostory_likes_user', columns: ['user_id'])]
class GeoStoryLike
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'user_id', type: 'guid')]
    private string $userId;

    #[ORM\Column(name: 'geostory_id', type: 'guid')]
    private string $geoStoryId;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        string $userId,
        string $geoStoryId,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id         = $id;
        $this->userId     = $userId;
        $this->geoStoryId = $geoStoryId;
        $this->createdAt  = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): string                    { return $this->id; }
    public function getUserId(): string                { return $this->userId; }
    public function getGeoStoryId(): string            { return $this->geoStoryId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
