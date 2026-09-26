<?php

declare(strict_types=1);

namespace App\Badges\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Mapeada para que el esquema de Doctrine la conozca (si no, un
 * `schema:update` la borraría); se lee y escribe por `BadgeRepository`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'badges')]
#[ORM\UniqueConstraint(name: 'uniq_badges_slug', columns: ['slug'])]
class Badge
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 50)]
    private string $slug;

    /** Clave de traducción: `badge.<slug>`. */
    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    #[ORM\Column(type: 'string', length: 16)]
    private string $emoji;

    #[ORM\Column(name: '`order`', type: 'integer', options: ['default' => 0])]
    private int $order = 0;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, string $slug, string $emoji, int $order = 0)
    {
        $this->id = $id;
        $this->slug = $slug;
        $this->name = 'badge.' . $slug;
        $this->emoji = $emoji;
        $this->order = $order;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getSlug(): string { return $this->slug; }
}
