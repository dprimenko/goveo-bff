<?php

declare(strict_types=1);

namespace App\Badges\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * En qué grupos se ofrece un badge como filtro: Ecológico y Terraza, sólo en
 * Gastronomía. Un negocio de otro grupo puede llevarlo igual; esto decide
 * únicamente dónde sale el chip.
 */
#[ORM\Entity]
#[ORM\Table(name: 'badge_categories')]
class BadgeCategory
{
    #[ORM\Id]
    #[ORM\Column(name: 'badge_id', type: 'guid')]
    private string $badgeId;

    #[ORM\Id]
    #[ORM\Column(name: 'category_id', type: 'guid')]
    private string $categoryId;

    public function __construct(string $badgeId, string $categoryId)
    {
        $this->badgeId = $badgeId;
        $this->categoryId = $categoryId;
    }
}
