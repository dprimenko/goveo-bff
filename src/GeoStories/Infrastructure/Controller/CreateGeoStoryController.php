<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\Business\Domain\BusinessManagerRepository;
use App\Business\Domain\BusinessRepository;
use App\GeoStories\Domain\GeoStory;
use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Infrastructure\Service\BunnyVideoService;
use App\Influencers\Domain\InfluencerRepository;
use App\Security\GoveoUser;
use App\Users\Domain\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Authenticated video upload. The backend proxies the file to Bunny Stream and
 * creates the GeoStory in `processing`; the Bunny webhook later flips it to
 * `ready`. The uploader is resolved from the JWT: an influencer posts to their
 * own feed (location comes from the request); a business manager posts on
 * behalf of a store (location comes from the store).
 */
#[Route('/api/geostories', name: 'geostories_create', methods: ['POST'])]
class CreateGeoStoryController
{
    private const VIDEO_MIME = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm'];

    public function __construct(
        private readonly Security $security,
        private readonly GeoStoryRepository $geoStories,
        private readonly BunnyVideoService $bunny,
        private readonly InfluencerRepository $influencers,
        private readonly BusinessManagerRepository $businessManagers,
        private readonly BusinessRepository $businesses,
        private readonly UserRepository $users,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof GoveoUser) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        // JWT `sub` (Keycloak) != local users.id (Supabase UUID). Bridge by email.
        $userId = ($user->getEmail() !== null
            ? $this->users->findByEmail($user->getEmail())?->getId()
            : null) ?? $user->getId();

        /** @var UploadedFile|null $video */
        $video = $request->files->get('video');
        if ($video === null) {
            return new JsonResponse(['error' => 'Missing video file'], Response::HTTP_BAD_REQUEST);
        }
        if (!in_array($video->getMimeType(), self::VIDEO_MIME, true)) {
            return new JsonResponse(['error' => 'Unsupported video type'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $title       = trim((string) $request->request->get('title', '')) ?: null;
        $description  = trim((string) $request->request->get('description', '')) ?: null;
        $categoryId   = $request->request->get('categoryId') ?: null;
        $requestedBiz = $request->request->get('businessId') ?: null;

        // ── Resolve the owner (influencer vs business) from the JWT ──────────
        $influencer   = $this->influencers->findByUserId($userId);
        $managedBizIds = array_map(
            static fn ($m) => $m->getBusinessId(),
            $this->businessManagers->findByUserId($userId),
        );

        $influencerId = null;
        $businessId   = null;
        // EWKT string ('SRID=4326;POINT(lng lat)') — the PostGIS geometry type
        // binds via ST_GeomFromEWKT(?), so the value must be an EWKT string, not
        // a GeoJSON array (Doctrine would try to bind the array verbatim).
        $location     = null;

        // Business post if a managed businessId is requested (or the user manages
        // exactly one store and is not an influencer).
        if ($requestedBiz !== null && in_array($requestedBiz, $managedBizIds, true)) {
            $businessId = $requestedBiz;
        } elseif ($influencer !== null) {
            $influencerId = $influencer->getId();
        } elseif (count($managedBizIds) === 1) {
            $businessId = $managedBizIds[0];
        } else {
            return new JsonResponse(['error' => 'User is neither an influencer nor a business manager'], Response::HTTP_FORBIDDEN);
        }

        if ($businessId !== null) {
            // Store post → location + category come from the store itself.
            $business = $this->businesses->findById($businessId);
            $location = $business?->getLocation();
            if ($categoryId === null && $business !== null) {
                $categoryId = $business->getCategoryId();
            }
        } else {
            // Influencer post → location from the request (required).
            $lat = $request->request->get('lat');
            $lng = $request->request->get('lng');
            if ($lat !== null && $lng !== null && $lat !== '' && $lng !== '') {
                $location = sprintf('SRID=4326;POINT(%.7f %.7f)', (float) $lng, (float) $lat);
            }
        }

        // ── Upload to Bunny + persist ───────────────────────────────────────
        try {
            $uploaded = $this->bunny->uploadVideo($video, $title ?? 'GeoStory');
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Video upload failed', 'detail' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        $geoStory = new GeoStory(
            id: Uuid::v4()->toRfc4122(),
            thumbnail: $uploaded['thumbnail'],
            url: $uploaded['url'],
            title: $title,
            description: $description,
            categoryId: $categoryId,
            influencerId: $influencerId,
            businessId: $businessId,
            location: $location,
            isMain: false,
            meta: null,
            status: GeoStory::STATUS_PROCESSING,
            providerVideoId: $uploaded['videoId'],
        );
        // Published so it shows in the owner's profile immediately (as processing);
        // discovery feeds still hide it until status = ready (see findFeed).
        $geoStory->publish();
        $this->geoStories->save($geoStory);

        return new JsonResponse([
            'id'            => $geoStory->getId(),
            'title'         => $geoStory->getTitle(),
            'description'   => $geoStory->getDescription(),
            'url'           => $geoStory->getUrl(),
            'thumbnail'     => $geoStory->getThumbnail(),
            'status'        => $geoStory->getStatus(),
            'influencer_id' => $geoStory->getInfluencerId(),
            'business_id'   => $geoStory->getBusinessId(),
            'category_id'   => $geoStory->getCategoryId(),
        ], Response::HTTP_CREATED);
    }
}
