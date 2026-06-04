<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Domain\GeoStoryWithDistance;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/geostories', name: 'pub_geostories_detail_')]
class GetGeoStoryController
{
    private const DEFAULT_LAT  = 41.3873974;
    private const DEFAULT_LONG = 2.168568;

    public function __construct(
        private readonly GeoStoryRepository $repository,
    ) {}

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function __invoke(string $id, Request $request): Response
    {
        $lat = (float) ($request->query->get('lat', self::DEFAULT_LAT));
        $lng = (float) ($request->query->get('lng', self::DEFAULT_LONG));

        $story = $this->repository->findByIdWithDistance($id, $lat, $lng);

        if ($story === null) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serialize($story));
    }

    private function serialize(GeoStoryWithDistance $s): array
    {
        return [
            'id'                => $s->id,
            'title'             => $s->title,
            'url'               => $s->url,
            'meta'              => $s->meta,
            'likes'             => $s->likes,
            'lat'               => $s->lat,
            'long'              => $s->long,
            'dist_meters'       => $s->distMeters,
            'started_at'        => $s->startedAt?->format(\DateTimeInterface::ATOM),
            'created_at'        => $s->createdAt?->format(\DateTimeInterface::ATOM),
            'published_at'      => $s->publishedAt?->format(\DateTimeInterface::ATOM),
            'influencer_id'     => $s->influencerId,
            'influencer_name'   => $s->influencerName,
            'influencer_avatar' => $s->influencerAvatar,
            'business_id'       => $s->businessId,
            'business_name'     => $s->businessName,
            'business_avatar'   => $s->businessAvatar,
            'business_meta'     => $s->businessMeta,
            'category_id'       => $s->categoryId,
            'category_name'     => $s->categoryName,
        ];
    }
}
