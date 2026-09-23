<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use App\Business\Domain\BusinessRepository;
use App\GeoStories\Domain\GeoStoryRepository;
use App\Influencers\Domain\InfluencerRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * PUT /api/admin/geostories/{id}/owner   {"type": "business"|"influencer", "id": "…"}
 *
 * Pasa un vídeo o una foto a otro dueño. Existe sobre todo por el scraping de
 * eventos: lo que no se reconoce se cuelga de «Agenda Goveo», y cuando la sala
 * se da de alta después —o el nombre casó con el sitio equivocado— hay que
 * poder moverlo sin borrarlo y volver a importarlo, que además no se podría
 * (`external_ref` lo impide).
 *
 * Sirve para cualquier publicación, no sólo las importadas.
 *
 * **La ubicación no se toca**, salvo que no tuviera: la del evento es donde
 * ocurre, que puede no ser la dirección fiscal del negocio nuevo.
 */
#[Route('/api/admin/geostories/{id}/owner', name: 'admin_geostory_owner', methods: ['PUT'])]
#[IsGranted('ROLE_GEOSTORY_MODERATE')]
class ChangeGeoStoryOwnerController
{
    public function __construct(
        private readonly GeoStoryRepository $geoStories,
        private readonly BusinessRepository $businesses,
        private readonly InfluencerRepository $influencers,
    ) {}

    public function __invoke(string $id, Request $request): Response
    {
        $story = $this->geoStories->findById($id);
        if ($story === null) {
            return new JsonResponse(['error' => 'GeoStory not found'], Response::HTTP_NOT_FOUND);
        }

        $body    = json_decode($request->getContent(), true);
        $type    = is_array($body) ? ($body['type'] ?? null) : null;
        $ownerId = is_array($body) ? trim((string) ($body['id'] ?? '')) : '';

        if (!in_array($type, ['business', 'influencer'], true) || $ownerId === '') {
            return new JsonResponse(['error' => 'Send {"type": "business"|"influencer", "id": "…"}'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($type === 'business') {
            $business = $this->businesses->findById($ownerId);
            if ($business === null || $business->getDeletedAt() !== null) {
                return new JsonResponse(['error' => 'Business not found'], Response::HTTP_NOT_FOUND);
            }

            $story->reassignTo($business->getId(), null);
            if ($story->getLocation() === null && $business->getLatitude() !== null && $business->getLongitude() !== null) {
                $story->setLocation($business->getLatitude(), $business->getLongitude());
            }
            $owner = ['type' => 'business', 'id' => $business->getId(), 'name' => $business->getName(), 'avatar' => $business->getAvatar()];
        } else {
            $influencer = $this->influencers->findById($ownerId);
            if ($influencer === null || $influencer->isDeleted()) {
                return new JsonResponse(['error' => 'Influencer not found'], Response::HTTP_NOT_FOUND);
            }

            $story->reassignTo(null, $influencer->getId());
            $owner = ['type' => 'influencer', 'id' => $influencer->getId(), 'name' => $influencer->getName(), 'avatar' => $influencer->getAvatar()];
        }

        $this->geoStories->save($story);

        return new JsonResponse(['id' => $story->getId(), 'owner' => $owner]);
    }
}
