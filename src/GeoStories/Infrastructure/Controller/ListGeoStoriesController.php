<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Domain\GeoStoryWithDistance;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/geostories', name: 'pub_geostories_', methods: ['GET'])]
class ListGeoStoriesController
{
    private const DEFAULT_LAT  = 41.3873974;
    private const DEFAULT_LONG = 2.168568;

    public function __construct(
        private readonly GeoStoryRepository $repository,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $lat         = (float) ($request->query->get('lat',      self::DEFAULT_LAT));
        $lng         = (float) ($request->query->get('lng',      self::DEFAULT_LONG));
        $page        = (int)   ($request->query->get('page',     0));
        $size        = min((int) ($request->query->get('size',   10)), 100);
        $maxDist     = $request->query->has('maxDist')       ? (float) $request->query->get('maxDist')       : null;
        $ignore      = $request->query->get('ignore');
        $feedType    = $request->query->get('feedType');
        $category    = $request->query->get('category');
        $notCategory = $request->query->get('notCategory');
        $businessId  = $request->query->get('businessId');
        $influencerId = $request->query->get('influencerId');

        $result = $this->repository->findFeed(
            latitude:      $lat,
            longitude:     $lng,
            page:          $page,
            size:          $size,
            maxDistMeters: $maxDist,
            ignoreId:      $ignore,
            feedType:      $feedType,
            categoryId:    $category,
            notCategoryId: $notCategory,
            businessId:    $businessId,
            influencerId:  $influencerId,
        );

        return new JsonResponse([
            'items' => array_map(fn (GeoStoryWithDistance $s) => $this->serialize($s), $result['items']),
            'total' => $result['total'],
        ]);
    }

    private function serialize(GeoStoryWithDistance $s): array
    {
        return [
            'id'               => $s->id,
            'title'            => $s->title,
            'url'              => $s->url,
            'meta'             => $s->meta,
            'likes'            => $s->likes,
            'lat'              => $s->lat,
            'long'             => $s->long,
            'dist_meters'      => $s->distMeters,
            'started_at'       => $s->startedAt?->format(\DateTimeInterface::ATOM),
            'created_at'       => $s->createdAt?->format(\DateTimeInterface::ATOM),
            'published_at'     => $s->publishedAt?->format(\DateTimeInterface::ATOM),
            'influencer_id'    => $s->influencerId,
            'influencer_name'  => $s->influencerName,
            'influencer_avatar' => $s->influencerAvatar,
            'business_id'      => $s->businessId,
            'business_name'    => $s->businessName,
            'business_avatar'  => $s->businessAvatar,
            'business_meta'    => $s->businessMeta,
            'category_id'      => $s->categoryId,
            'category_name'    => $s->categoryName,
        ];
    }
}
