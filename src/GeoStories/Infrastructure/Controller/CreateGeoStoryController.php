<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\Backoffice\Application\ReviewQueueNotifier;
use App\Business\Domain\BusinessManagerRepository;
use App\Categories\Application\Subcategories;
use App\Business\Domain\BusinessRepository;
use App\GeoStories\Domain\GeoStory;
use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Infrastructure\Service\BunnyVideoService;
use App\GeoStories\Infrastructure\Service\StorySchedule;
use App\Shared\Infrastructure\Storage\BunnyStorageService;
use App\Shared\Infrastructure\Storage\StorageException;
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
 *
 * **También se puede publicar una foto** (`image` en vez de `video`). Es el
 * mismo contenido con el mismo formulario: lo único que cambia es que no hay
 * nada que codificar, así que no pasa por Bunny Stream sino por el
 * almacenamiento de imágenes y **nace `ready`** — esperar un aviso que nunca
 * llegaría la dejaría «procesando» para siempre.
 *
 * La miniatura de una foto es la propia foto: no hay fotograma que extraer, y
 * dejarla vacía obligaría a cada pantalla a inventarse un hueco.
 */
#[Route('/api/geostories', name: 'geostories_create', methods: ['POST'])]
class CreateGeoStoryController
{
    private const VIDEO_MIME = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm'];

    /** Lo que se acepta como foto. El almacenamiento vuelve a mirarlo por dentro. */
    private const IMAGE_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly Security $security,
        private readonly GeoStoryRepository $geoStories,
        private readonly BunnyVideoService $bunny,
        private readonly InfluencerRepository $influencers,
        private readonly BusinessManagerRepository $businessManagers,
        private readonly BusinessRepository $businesses,
        private readonly UserRepository $users,
        private readonly StorySchedule $schedule,
        private readonly BunnyStorageService $storage,
        private readonly ReviewQueueNotifier $reviewQueue,
        private readonly Subcategories $subcategories,
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
        /** @var UploadedFile|null $image */
        $image = $request->files->get('image');

        if ($video === null && $image === null) {
            return new JsonResponse(['error' => 'Missing video file'], Response::HTTP_BAD_REQUEST);
        }
        // Las dos cosas a la vez no: publicar es publicar una, y elegir por
        // quien sube sería adivinar cuál quería.
        if ($video !== null && $image !== null) {
            return new JsonResponse(['error' => 'Send either a video or an image'], Response::HTTP_BAD_REQUEST);
        }

        $file    = $video ?? $image;
        $isImage = $image !== null;
        $allowed = $isImage ? self::IMAGE_MIME : self::VIDEO_MIME;

        if (!in_array($file->getMimeType(), $allowed, true)) {
            return new JsonResponse(
                ['error' => $isImage ? 'Unsupported image type' : 'Unsupported video type'],
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }
        // Un fichero vacío llegaba hasta Bunny: se creaba el objeto, se subían
        // cero bytes y el vídeo se quedaba «procesando» para siempre, con una
        // fila en la base apuntando a algo que no existe y basura en la
        // librería que nadie limpia. Aquí se corta antes de tocar nada.
        if ($file->getSize() === 0) {
            return new JsonResponse(['error' => 'empty_video'], Response::HTTP_BAD_REQUEST);
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

        // Desde el panel se sube el vídeo de cualquier tienda: es su trabajo, y
        // quien lo hace no gestiona ese negocio. Se exige el `businessId` a
        // propósito —no hay «su» tienda que adivinar— y que exista.
        $fromBackoffice = $this->security->isGranted('ROLE_GEOSTORY_MODERATE');

        if ($fromBackoffice && $requestedBiz !== null && !in_array($requestedBiz, $managedBizIds, true)) {
            if ($this->businesses->findById($requestedBiz) === null) {
                return new JsonResponse(['error' => 'Business not found'], Response::HTTP_NOT_FOUND);
            }
            $businessId = $requestedBiz;
        } elseif ($requestedBiz !== null && in_array($requestedBiz, $managedBizIds, true)) {
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

        // ── Vigencia (eventos y noticias) ───────────────────────────────────
        //
        // Se valida **antes** de subir a Bunny: con la fecha mal, subir primero
        // sería dejar el vídeo colgado allí sin fila que lo apunte, y nadie lo
        // borraría después.
        $rawStart = $request->request->get('started_at') ?: null;
        $rawEnd   = $request->request->get('ended_at') ?: null;
        $scheduleError = $this->schedule->validateNew($categoryId, $rawStart, $rawEnd);
        if ($scheduleError !== null) {
            return new JsonResponse(['error' => $scheduleError], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // ── Enlace externo ──────────────────────────────────────────────────
        //
        // Antes de subir nada, como las fechas: con el enlace mal escrito, subir
        // primero dejaría el fichero colgado sin fila que lo apunte.
        // Y **sólo en eventos**: es donde tiene sentido mandar fuera a comprar
        // una entrada o reservar mesa. En una geostory cualquiera, un botón que
        // saca de la app compite con el vídeo, que es lo que se ha venido a ver.
        $link = $this->schedule->isEvent($categoryId)
            ? $this->readLink($request)
            : ['url' => null, 'action' => null];
        if ($link instanceof Response) {
            return $link;
        }

        $storyId = Uuid::v4()->toRfc4122();

        // ── Upload + persist ────────────────────────────────────────────────
        try {
            $uploaded = $isImage
                ? $this->uploadImage($file, $storyId)
                : $this->bunny->uploadVideo($file, $title ?? 'GeoStory');
        } catch (StorageException $e) {
            return new JsonResponse(['error' => 'invalid_image', 'detail' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Video upload failed', 'detail' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        $geoStory = new GeoStory(
            id: $storyId,
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
            // Una foto no se codifica: dejarla en «procesando» sería esperar un
            // aviso de Bunny Stream que nadie va a mandar.
            status: $isImage ? GeoStory::STATUS_READY : GeoStory::STATUS_PROCESSING,
            providerVideoId: $uploaded['videoId'],
            mediaType: $isImage ? GeoStory::MEDIA_IMAGE : GeoStory::MEDIA_VIDEO,
        );
        $geoStory->linkTo($link['url'], $link['action']);
        // La subcategoría de un evento; sin ella, o con una que no es de
        // Eventos, va a «Otros» (ver Subcategories).
        $geoStory->setSubcategoryId($this->subcategories->resolve(
            $categoryId,
            (string) $request->request->get('subcategoryId', ''),
        ));
        // El subnivel, opcional y sólo si es de ese tipo.
        $geoStory->setSubtypeId($this->subcategories->resolveSubtype(
            $geoStory->getSubcategoryId(),
            (string) $request->request->get('subtypeId', ''),
        ));
        $this->schedule->apply($geoStory, $categoryId, $rawStart, $rawEnd);

        // Published so it shows in the owner's profile immediately (as processing);
        // discovery feeds still hide it until status = ready (see findFeed).
        // Un vídeo subido desde el panel nace validado: lo sube justo quien
        // tendría que validarlo, y dejarlo esperando en su propia cola sería
        // pedirle que se apruebe a sí mismo.
        if ($fromBackoffice) {
            $geoStory->verify();
        }

        $this->geoStories->save($geoStory);

        // Una foto no pasa por Bunny, así que nadie avisaba de ella.
        //
        // El aviso de «hay algo nuevo por validar» lo manda quien ve el cambio
        // de «procesando» a «listo»: el webhook de Bunny, el repaso del perfil
        // o el comando de reconciliación. Una foto nace lista y no pasa por
        // ninguno de los tres, así que entraba en la cola en silencio y allí se
        // quedaba hasta que alguien mirara por su cuenta.
        //
        // El notificador decide solo si toca —lo validado no se anuncia—, así
        // que aquí basta con contárselo.
        if ($geoStory->getStatus() === GeoStory::STATUS_READY) {
            $this->reviewQueue->geoStoryPendingReview($geoStory);
        }

        return new JsonResponse([
            'id'            => $geoStory->getId(),
            'title'         => $geoStory->getTitle(),
            'description'   => $geoStory->getDescription(),
            'url'           => $geoStory->getUrl(),
            'thumbnail'     => $geoStory->getThumbnail(),
            'status'        => $geoStory->getStatus(),
            'media_type'    => $geoStory->getMediaType(),
            'link_url'      => $geoStory->getLinkUrl(),
            'link_action'   => $geoStory->getLinkAction(),
            'influencer_id' => $geoStory->getInfluencerId(),
            'business_id'   => $geoStory->getBusinessId(),
            'category_id'   => $geoStory->getCategoryId(),
            'subcategory_id' => $geoStory->getSubcategoryId(),
            'subtype_id'     => $geoStory->getSubtypeId(),
            'started_at'    => $geoStory->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'ended_at'      => $geoStory->getEndedAt()?->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }

    /**
     * La foto, a su sitio del almacenamiento.
     *
     * La ruta lleva el id de la geostory igual que las de producto llevan el
     * suyo: así se sabe de quién es un fichero mirándolo, y borrar la geostory
     * deja claro qué hay que borrar con ella.
     *
     * @return array{videoId: ?string, url: string, thumbnail: string}
     */
    private function uploadImage(UploadedFile $file, string $storyId): array
    {
        $url = $this->storage->upload(
            fn (string $ext): string => sprintf('geostories/%s/%d.%s', $storyId, time(), $ext),
            (string) file_get_contents($file->getPathname()),
        );

        // La miniatura es la propia foto: no hay fotograma que sacar.
        return ['videoId' => null, 'url' => $url, 'thumbnail' => $url];
    }

    /**
     * El enlace externo que acompaña a la publicación.
     *
     * Mismas reglas que en el producto: sólo `http`/`https`, porque esto acaba
     * en un botón que la app abre a ciegas, y se guarda la **intención**
     * (comprar / reservar / informarse) y no el rótulo, para que el botón salga
     * en el idioma de quien mira.
     *
     * @return array{url: ?string, action: ?string}|Response
     */
    private function readLink(Request $request): array|Response
    {
        $url = trim((string) $request->request->get('link_url', ''));
        if ($url === '') {
            return ['url' => null, 'action' => null];
        }

        if (mb_strlen($url) > 2048
            || !filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
        ) {
            return new JsonResponse(['error' => 'invalid_link'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return ['url' => $url, 'action' => (string) $request->request->get('link_action', '')];
    }
}
