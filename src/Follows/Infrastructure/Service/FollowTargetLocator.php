<?php

declare(strict_types=1);

namespace App\Follows\Infrastructure\Service;

use App\Business\Domain\BusinessRepository;
use App\Follows\Domain\FollowTarget;
use App\Influencers\Domain\InfluencerRepository;

/**
 * Resuelve el destino de un follow: comprueba que existe (para no guardar
 * filas huérfanas) y devuelve su `meta`, que es donde vive el override manual
 * del contador de seguidores.
 */
final class FollowTargetLocator
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly InfluencerRepository $influencers,
    ) {}

    /**
     * @return array{found: bool, meta: array|null} `meta` sólo tiene sentido si `found`
     */
    public function locate(FollowTarget $type, string $id): array
    {
        $target = match ($type) {
            FollowTarget::Business   => $this->businesses->findById($id),
            FollowTarget::Influencer => $this->influencers->findById($id),
        };

        return $target === null
            ? ['found' => false, 'meta' => null]
            : ['found' => true, 'meta' => $target->getMeta()];
    }
}
