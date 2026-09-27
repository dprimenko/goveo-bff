<?php

declare(strict_types=1);

namespace App\Moderation\Application;

use App\Business\Application\BusinessArchiver;
use App\Business\Domain\BusinessRepository;
use App\GeoStories\Domain\GeoStoryRepository;
use App\Influencers\Domain\InfluencerRepository;
use App\Moderation\Domain\ReportTarget;
use App\Products\Domain\ProductRepository;
use Doctrine\DBAL\Connection;

/**
 * Retirar lo denunciado. **Archiva, no destruye**, igual que el resto del
 * panel: `deleted_at` lo quita de la app entera y se puede deshacer desde las
 * pantallas de siempre (vídeos, negocios) si resulta que la denuncia no tenía
 * razón.
 *
 * Retirar una cuenta se lleva todo lo suyo: un negocio archivado con sus
 * vídeos en el feed seguiría enseñando justo lo que se ha querido quitar.
 */
final class ContentTakedown
{
    public function __construct(
        private readonly GeoStoryRepository $geoStories,
        private readonly ProductRepository $products,
        private readonly BusinessRepository $businesses,
        private readonly BusinessArchiver $businessArchiver,
        private readonly InfluencerRepository $influencers,
        private readonly Connection $db,
    ) {}

    /** @return bool false si ya no existe */
    public function remove(ReportTarget $type, string $id): bool
    {
        switch ($type) {
            case ReportTarget::GeoStory:
                $story = $this->geoStories->findById($id);
                if ($story === null) {
                    return false;
                }
                if ($story->getDeletedAt() === null) {
                    $story->softDelete();
                    $this->geoStories->save($story);
                }

                return true;

            case ReportTarget::Product:
                $product = $this->products->findById($id);
                if ($product === null) {
                    return false;
                }
                $product->softDelete();
                $this->products->save($product);

                return true;

            case ReportTarget::Business:
            case ReportTarget::Influencer:
                return $this->removeAccount($type->value, $id);
        }
    }

    /** Retira la cuenta (negocio o influencer) y todo lo que ha publicado. */
    public function removeAccount(string $type, string $id): bool
    {
        if ($type === 'business') {
            $business = $this->businesses->findById($id);
            if ($business === null) {
                return false;
            }
            $this->businessArchiver->archive($business);

            return true;
        }

        $influencer = $this->influencers->findById($id);
        if ($influencer === null) {
            return false;
        }

        $influencer->softDelete();
        $this->influencers->save($influencer);

        $this->db->executeStatement(
            'UPDATE geostories SET deleted_at = NOW(), updated_at = NOW()
              WHERE influencer_id = ? AND deleted_at IS NULL',
            [$id],
        );

        return true;
    }
}
