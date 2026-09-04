<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\Business\Domain\BusinessRepository;
use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Infrastructure\Service\BunnyVideoService;
use App\GeoStories\Infrastructure\Service\StorySchedule;
use App\GeoStories\Infrastructure\Service\GeoStoryOwnership;
use App\Security\GoveoUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Edita una GeoStory. Se editan los mismos campos que en el alta: título,
 * descripción y categoría —también en un vídeo de tienda, que es lo que
 * distingue uno fijo de un evento o una noticia— y, sólo en influencer, la
 * localización: la de una tienda es la de la tienda.
 *
 * Cambiar de categoría **borra la vigencia anterior**: las fechas que tuviera
 * las puso la categoría de antes y con su regla, así que heredarlas colaba a un
 * evento la fecha de publicación de cuando era noticia.
 *
 * Opcionalmente sustituye el vídeo: el nuevo se sube a Bunny (otro GUID, estado
 * `processing`) y el anterior se borra allí.
 *
 * Multipart POST (not PATCH) because PHP only parses multipart bodies for POST.
 */
#[Route('/api/geostories/{id}', name: 'geostories_update', methods: ['POST'])]
class UpdateGeoStoryController
{
    private const VIDEO_MIME = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm'];

    public function __construct(
        private readonly Security $security,
        private readonly GeoStoryRepository $geoStories,
        private readonly BunnyVideoService $bunny,
        private readonly GeoStoryOwnership $ownership,
        private readonly StorySchedule $schedule,
        private readonly BusinessRepository $businesses,
    ) {}

    public function __invoke(string $id, Request $request): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof GoveoUser) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $story = $this->geoStories->findById($id);
        if ($story === null || $story->isDeleted()) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->ownership->userOwns($user, $story)) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $isBusiness = $story->getBusinessId() !== null;

        // ── Metadata ────────────────────────────────────────────────────────
        if ($request->request->has('title')) {
            $story->setTitle(trim((string) $request->request->get('title')) ?: null);
        }
        if ($request->request->has('description')) {
            $story->setDescription(trim((string) $request->request->get('description')) ?: null);
        }
        // La localización de un vídeo de tienda es la de la tienda y no se
        // toca. La categoría sí: es lo que distingue lo que se queda fijo en su
        // perfil de un evento o una noticia, que caducan.
        // La de antes, para saber si ha cambiado: es lo que decide si la
        // vigencia guardada sigue valiendo o la puso una categoría que ya no es
        // la suya.
        $previousCategoryId = $story->getCategoryId();

        if ($request->request->has('categoryId')) {
            $categoryId = trim((string) $request->request->get('categoryId'));

            if ($categoryId !== '') {
                $story->setCategoryId($categoryId);
            } elseif ($isBusiness) {
                // Vacío en una tienda es «lo mío de siempre»: vuelve a la
                // categoría del negocio. En un influencer no significa nada,
                // así que se ignora en vez de dejarlo sin categoría.
                $business = $this->businesses->findById((string) $story->getBusinessId());
                if ($business !== null) {
                    $story->setCategoryId($business->getCategoryId());
                }
            }
        }

        if (!$isBusiness) {
            $lat = $request->request->get('lat');
            $lng = $request->request->get('lng');
            if ($lat !== null && $lng !== null && $lat !== '' && $lng !== '') {
                $story->setLocation((float) $lat, (float) $lng);
            }
        }

        // ── Vigencia (eventos y noticias) ───────────────────────────────────
        //
        // Se recalcula aunque no lleguen fechas: la categoría puede haber
        // cambiado, y pasar un vídeo cualquiera a Eventos sin darle fecha —o al
        // revés, sacarlo de Eventos y dejarle la caducidad puesta— es la forma
        // de que acabe con una vigencia que no le corresponde.
        //
        // Antes de la subida por lo mismo que en el alta: un vídeo nuevo en
        // Bunny y un guardado que falla dejan el fichero colgado allí.
        // Después de resolver la categoría: es ella la que decide si este vídeo
        // caduca y con qué regla.
        $scheduleError = $this->schedule->apply(
            $story,
            $story->getCategoryId(),
            $request->request->has('started_at') ? (string) $request->request->get('started_at') : null,
            $request->request->has('ended_at')   ? (string) $request->request->get('ended_at')   : null,
            categoryChanged: $previousCategoryId !== $story->getCategoryId(),
        );
        if ($scheduleError !== null) {
            return new JsonResponse(['error' => $scheduleError], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // ── Optional video overwrite ────────────────────────────────────────
        /** @var UploadedFile|null $video */
        $video = $request->files->get('video');
        if ($video !== null) {
            if (!in_array($video->getMimeType(), self::VIDEO_MIME, true)) {
                return new JsonResponse(['error' => 'Unsupported video type'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
            }
            try {
                $uploaded = $this->bunny->uploadVideo($video, $story->getTitle() ?? 'GeoStory');
            } catch (\Throwable $e) {
                return new JsonResponse(['error' => 'Video upload failed', 'detail' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
            }
            $oldGuid = $story->getProviderVideoId();
            $story
                ->setUrl($uploaded['url'])
                ->setThumbnail($uploaded['thumbnail'])
                ->setProviderVideoId($uploaded['videoId'])
                ->markProcessing();
            if ($oldGuid !== null && $oldGuid !== $uploaded['videoId']) {
                $this->bunny->deleteVideo($oldGuid);
            }
        }

        $this->geoStories->save($story);

        return new JsonResponse([
            'id'            => $story->getId(),
            'title'         => $story->getTitle(),
            'description'   => $story->getDescription(),
            'url'           => $story->getUrl(),
            'thumbnail'     => $story->getThumbnail(),
            'status'        => $story->getStatus(),
            'influencer_id' => $story->getInfluencerId(),
            'business_id'   => $story->getBusinessId(),
            'category_id'   => $story->getCategoryId(),
            'started_at'    => $story->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'ended_at'      => $story->getEndedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }
}
