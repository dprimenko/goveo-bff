<?php

declare(strict_types=1);

namespace App\Moderation\Application;

use App\Business\Domain\BusinessRepository;
use App\GeoStories\Domain\GeoStoryRepository;
use App\Influencers\Domain\InfluencerRepository;
use App\Moderation\Domain\ReportTarget;
use App\Products\Domain\ProductRepository;

/**
 * Lo denunciado: si existe, cómo llamarlo en el aviso y **de quién es**.
 *
 * El dueño se resuelve aquí para que la denuncia lo guarde: el panel agrupa por
 * cuenta —cinco denuncias a cinco vídeos del mismo influencer dicen más que
 * cualquiera de ellas sola— y bloquear sabe a quién bloquear partiendo de un vídeo.
 */
final class ReportedContent
{
    public function __construct(
        private readonly GeoStoryRepository $geoStories,
        private readonly BusinessRepository $businesses,
        private readonly InfluencerRepository $influencers,
        private readonly ProductRepository $products,
    ) {}

    /**
     * @return array{label: string, owner_type: ?string, owner_id: ?string, owner_name: ?string}|null
     *         null si no existe: no se guardan denuncias huérfanas.
     */
    public function locate(ReportTarget $type, string $id): ?array
    {
        if (!self::isUuid($id)) {
            return null;
        }

        switch ($type) {
            case ReportTarget::GeoStory:
                $story = $this->geoStories->findById($id);
                if ($story === null) {
                    return null;
                }
                [$ownerType, $ownerId] = $story->getBusinessId() !== null
                    ? ['business', $story->getBusinessId()]
                    : ['influencer', $story->getInfluencerId()];

                return [
                    'label'      => $story->getTitle() ?? '(sin título)',
                    'owner_type' => $ownerId !== null ? $ownerType : null,
                    'owner_id'   => $ownerId,
                    'owner_name' => $this->ownerName($ownerType, $ownerId),
                ];

            case ReportTarget::Product:
                $product = $this->products->findById($id);
                if ($product === null) {
                    return null;
                }

                return [
                    'label'      => $product->getTitle(),
                    'owner_type' => 'business',
                    'owner_id'   => $product->getBusinessId(),
                    'owner_name' => $this->ownerName('business', $product->getBusinessId()),
                ];

            case ReportTarget::Business:
                $business = $this->businesses->findById($id);

                return $business === null ? null : [
                    'label'      => $business->getName() ?? '(sin nombre)',
                    'owner_type' => 'business',
                    'owner_id'   => $id,
                    'owner_name' => $business->getName(),
                ];

            case ReportTarget::Influencer:
                $influencer = $this->influencers->findById($id);

                return $influencer === null ? null : [
                    'label'      => $influencer->getName(),
                    'owner_type' => 'influencer',
                    'owner_id'   => $id,
                    'owner_name' => $influencer->getName(),
                ];
        }
    }

    private function ownerName(string $type, ?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return $type === 'business'
            ? $this->businesses->findById($id)?->getName()
            : $this->influencers->findById($id)?->getName();
    }

    /** Un id que no es un UUID haría fallar la query en Postgres con un 500. */
    private static function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }
}
