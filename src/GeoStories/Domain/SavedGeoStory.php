<?php

declare(strict_types=1);

namespace App\GeoStories\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Un usuario ha guardado una geostory («Guardados», como en Instagram).
 *
 * Sin id propio: la pareja usuario + vídeo ya es única y es lo único por lo
 * que se busca. `user_id` es el id local (`users.id`), no el `sub` del JWT.
 * Sin clave ajena, como `geostory_likes`: los borrados definitivos de vídeos
 * limpian estas filas a mano.
 */
#[ORM\Entity]
#[ORM\Table(name: 'saved_geostories')]
#[ORM\Index(name: 'idx_saved_geostories_user', columns: ['user_id', 'created_at'])]
#[ORM\Index(name: 'idx_saved_geostories_geostory', columns: ['geostory_id'])]
class SavedGeoStory
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: 'guid')]
    private string $userId;

    #[ORM\Id]
    #[ORM\Column(name: 'geostory_id', type: 'guid')]
    private string $geoStoryId;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $userId, string $geoStoryId, ?\DateTimeImmutable $createdAt = null)
    {
        $this->userId     = $userId;
        $this->geoStoryId = $geoStoryId;
        $this->createdAt  = $createdAt ?? new \DateTimeImmutable();
    }

    public function getUserId(): string                { return $this->userId; }
    public function getGeoStoryId(): string            { return $this->geoStoryId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
