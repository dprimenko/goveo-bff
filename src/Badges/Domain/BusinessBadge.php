<?php

declare(strict_types=1);

namespace App\Badges\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'business_badges')]
#[ORM\Index(name: 'idx_business_badges_badge', columns: ['badge_id'])]
class BusinessBadge
{
    #[ORM\Id]
    #[ORM\Column(name: 'business_id', type: 'guid')]
    private string $businessId;

    #[ORM\Id]
    #[ORM\Column(name: 'badge_id', type: 'guid')]
    private string $badgeId;

    public function __construct(string $businessId, string $badgeId)
    {
        $this->businessId = $businessId;
        $this->badgeId = $badgeId;
    }
}
