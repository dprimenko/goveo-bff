<?php

declare(strict_types=1);

namespace App\Influencers\Application;

use App\Influencers\Domain\Influencer;
use App\Influencers\Domain\InfluencerRepository;
use Doctrine\DBAL\Connection;

/**
 * Archivar un influencer se lleva sus vídeos, y recuperarlo los devuelve.
 *
 * El mismo truco que `BusinessArchiver`: **una sola marca de tiempo para todo**,
 * y al recuperar sólo vuelven los vídeos con exactamente esa fecha. Lo que él
 * mismo había borrado antes, o lo que se retiró por una denuncia, se queda
 * borrado.
 */
final class InfluencerArchiver
{
    public function __construct(
        private readonly InfluencerRepository $influencers,
        private readonly Connection $db,
    ) {}

    /** @return array{videos: int} lo que se ha archivado con él */
    public function archive(Influencer $influencer): array
    {
        $influencer->softDelete();
        $this->influencers->save($influencer);

        $at = $influencer->getDeletedAt()?->format('Y-m-d H:i:sP');

        return [
            'videos' => $this->db->executeStatement(
                'UPDATE geostories SET deleted_at = ?, updated_at = ?
                  WHERE influencer_id = ? AND deleted_at IS NULL',
                [$at, $at, $influencer->getId()],
            ),
        ];
    }

    /** @return array{videos: int} lo que ha vuelto con él */
    public function restore(Influencer $influencer): array
    {
        $at = $influencer->getDeletedAt()?->format('Y-m-d H:i:sP');

        $influencer->restore();
        $this->influencers->save($influencer);

        if ($at === null) {
            return ['videos' => 0];
        }

        return [
            'videos' => $this->db->executeStatement(
                'UPDATE geostories SET deleted_at = NULL, updated_at = NOW()
                  WHERE influencer_id = ? AND deleted_at = ?',
                [$influencer->getId(), $at],
            ),
        ];
    }
}
