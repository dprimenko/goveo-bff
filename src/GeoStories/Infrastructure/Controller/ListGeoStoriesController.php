<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\GeoStories\Domain\GeoStoryRepository;
use App\Security\JwtUserResolver;
use App\Shared\Domain\UuidGenerator;
use App\GeoStories\Domain\GeoStory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/geostories', name: 'geostories_', methods: ['GET'])]
class ListGeoStoriesController
{
    public function __construct(
        private readonly GeoStoryRepository $repository,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $lat = $request->query->get('lat');
        $lng = $request->query->get('lng');
        $radius = (float) ($request->query->get('radius', 5000));
        $limit = (int) ($request->query->get('limit', 20));

        if ($lat !== null && $lng !== null) {
            $stories = $this->repository->findNearby(
                (float) $lat,
                (float) $lng,
                $radius,
                min($limit, 100),
            );
        } else {
            $stories = [];
        }

        return new JsonResponse(array_map(
            fn (GeoStory $s) => $this->serialize($s),
            $stories,
        ));
    }

    private function serialize(GeoStory $story): array
    {
        $location = $story->getLocation();
        return [
            'id'           => $story->getId(),
            'title'        => $story->getTitle(),
            'description'  => $story->getDescription(),
            'thumbnail'    => $story->getThumbnail(),
            'url'          => $story->getUrl(),
            'likes'        => $story->getLikes(),
            'views'        => $story->getViews(),
            'location'     => $location,
            'category_id'  => $story->getCategoryId(),
            'influencer_id' => $story->getInfluencerId(),
            'business_id'  => $story->getBusinessId(),
            'is_main'      => $story->isMain(),
            'published_at' => $story->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'created_at'   => $story->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
