<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\Business\Domain\BusinessRepository;
use App\Categories\Application\Subcategories;
use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Infrastructure\Service\BunnyVideoService;
use App\GeoStories\Infrastructure\Service\StorySchedule;
use App\GeoStories\Infrastructure\Service\GeoStoryOwnership;
use App\Shared\Infrastructure\Storage\BunnyStorageService;
use App\Shared\Infrastructure\Storage\StorageException;
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
 * `processing`) y el anterior se borra allí. Una foto se sustituye por otra foto
 * igual de sencillamente; lo que **no** se puede es cambiar de tipo sobre la
 * marcha —un vídeo que pasa a foto deja un fichero huérfano en la librería y una
 * tarjeta a medio camino—, así que para eso se borra y se vuelve a publicar.
 *
 * El enlace externo (`link_url` + `link_action`) se edita como cualquier otro
 * campo, y **mandarlo vacío es como se quita**.
 *
 * Multipart POST (not PATCH) because PHP only parses multipart bodies for POST.
 */
#[Route('/api/geostories/{id}', name: 'geostories_update', methods: ['POST'])]
class UpdateGeoStoryController
{
    private const VIDEO_MIME = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm'];
    private const IMAGE_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly Security $security,
        private readonly GeoStoryRepository $geoStories,
        private readonly BunnyVideoService $bunny,
        private readonly GeoStoryOwnership $ownership,
        private readonly StorySchedule $schedule,
        private readonly BusinessRepository $businesses,
        private readonly BunnyStorageService $storage,
        private readonly Subcategories $subcategories,
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
        // El panel edita vídeos de cualquiera: es su trabajo. Ver GeoStoryOwnership.
        if (!$this->ownership->userOwns($user, $story, allowBackoffice: true)) {
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

        // La subcategoría: la pedida si llega, y si no, la que tenía —o «Otros»
        // si la categoría acaba de cambiar a Eventos—. Fuera de Eventos se va.
        $story->setSubcategoryId($this->subcategories->resolve(
            $story->getCategoryId(),
            $request->request->has('subcategoryId')
                ? (string) $request->request->get('subcategoryId')
                : $story->getSubcategoryId(),
        ));

        // El subnivel: el pedido si llega, y si no el que tenía, siempre que
        // siga siendo del tipo —cambiar de tipo se lo lleva—.
        $story->setSubtypeId($this->subcategories->resolveSubtype(
            $story->getSubcategoryId(),
            $request->request->has('subtypeId')
                ? (string) $request->request->get('subtypeId')
                : $story->getSubtypeId(),
        ));

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
            // Sólo quien modera puede fijar la fecha de una noticia a mano; su
            // dueño se queda con la regla de siempre.
            allowManualDates: $this->security->isGranted('ROLE_GEOSTORY_MODERATE'),
        );
        if ($scheduleError !== null) {
            return new JsonResponse(['error' => $scheduleError], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // ── Enlace externo ──────────────────────────────────────────────────
        //
        // Sólo si viene: lo que no se manda se queda como estaba, y una cadena
        // vacía es cómo se quita el enlace. Igual que en el producto.
        // Sólo los eventos llevan enlace, así que dejar de serlo se lo lleva por
        // delante aunque nadie lo haya tocado: un botón de «comprar entradas»
        // en algo que ya no es un evento lleva a una página que no viene a
        // cuento, y quien cambió la categoría no tiene por qué acordarse.
        if (!$this->schedule->isEvent($story->getCategoryId())) {
            $story->linkTo(null, null);
        } elseif ($request->request->has('link_url')) {
            $link = $this->readLink($request);
            if ($link instanceof Response) {
                return $link;
            }
            $story->linkTo($link['url'], $link['action']);
        }

        // ── Optional image overwrite ────────────────────────────────────────
        /** @var UploadedFile|null $image */
        $image = $request->files->get('image');
        if ($image !== null) {
            if (!$story->isImage()) {
                return new JsonResponse(
                    ['error' => 'Cannot turn a video into an image'],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            if (!in_array($image->getMimeType(), self::IMAGE_MIME, true)) {
                return new JsonResponse(['error' => 'Unsupported image type'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
            }

            $anterior = $story->getUrl();
            try {
                $url = $this->storage->upload(
                    fn (string $ext): string => sprintf('geostories/%s/%d.%s', $story->getId(), time(), $ext),
                    (string) file_get_contents($image->getPathname()),
                );
            } catch (StorageException $e) {
                return new JsonResponse(['error' => 'invalid_image', 'detail' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // La miniatura de una foto es la propia foto.
            $story->setUrl($url)->setThumbnail($url);

            // La vieja, fuera: nadie la va a volver a pedir y el almacenamiento
            // se paga por lo que ocupa.
            if ($anterior !== '' && $anterior !== $url) {
                $this->storage->deleteByUrl($anterior);
            }
        }

        // ── Optional video overwrite ────────────────────────────────────────
        /** @var UploadedFile|null $video */
        $video = $request->files->get('video');
        if ($video !== null && $story->isImage()) {
            return new JsonResponse(
                ['error' => 'Cannot turn an image into a video'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
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
            'media_type'    => $story->getMediaType(),
            'link_url'      => $story->getLinkUrl(),
            'link_action'   => $story->getLinkAction(),
            'influencer_id' => $story->getInfluencerId(),
            'business_id'   => $story->getBusinessId(),
            'category_id'   => $story->getCategoryId(),
            'subcategory_id' => $story->getSubcategoryId(),
            'subtype_id'     => $story->getSubtypeId(),
            'started_at'    => $story->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'ended_at'      => $story->getEndedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Mismas reglas que en el alta: sólo `http`/`https`, y se guarda la
     * intención y no el rótulo. Ver `CreateGeoStoryController::readLink`.
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
