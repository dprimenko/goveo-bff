<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Controller;

use App\Backoffice\Application\ReviewQueueNotifier;
use App\GeoStories\Domain\EventDay;
use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Domain\GeoStoryWithDistance;
use App\GeoStories\Infrastructure\Service\BunnyVideoService;
use App\GeoStories\Infrastructure\Service\GeoStoryFeedSerializer;
use App\Shared\Application\ProfileOwnership;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/geostories', name: 'pub_geostories_', methods: ['GET'])]
class ListGeoStoriesController
{
    private const DEFAULT_LAT  = 41.3873974;
    private const DEFAULT_LONG = 2.168568;

    // Bunny numeric statuses: 0-3 in-flight, 4 finished, 5 failed.
    private const BUNNY_FINISHED = 4;
    private const BUNNY_FAILED   = 5;

    /** Avance de codificación por vídeo de Bunny, de la última consulta. */
    private array $progress = [];

    public function __construct(
        private readonly GeoStoryRepository $repository,
        private readonly BunnyVideoService $bunny,
        private readonly ReviewQueueNotifier $reviewQueue,
        private readonly ProfileOwnership $ownership,
        private readonly LocalUserResolver $currentUser,
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
        $subcategory = $request->query->get('subcategory');
        $subtype     = $request->query->get('subtype');
        $businessId  = $request->query->get('businessId');
        $influencerId = $request->query->get('influencerId');
        // `events`: sin eventos (la fila de vídeos de un perfil).
        $exclude      = $request->query->get('exclude');
        // Pestaña de Eventos: otro día que hoy, y la hora si se quiere.
        $eventDay     = EventDay::fromQuery($request->query->get('date'), $request->query->get('time'));
        // «Siguiendo»: sólo lo de las cuentas que sigue quien mira. Sin sesión
        // sale vacío, no un 401: la app pinta su propio mensaje.
        $following    = $request->query->getBoolean('following');

        // Los vídeos pendientes de validar sólo los ve su dueño, y para eso hay
        // que identificarse: la ruta es pública, pero si llega un token se lee.
        // Fiarse de un parámetro de la petición dejaría los vídeos sin revisar
        // de cualquiera a un `?mine=1` de distancia.
        $includeUnverified = $this->ownership->ownsInfluencer($influencerId)
            || $this->ownership->ownsBusiness($businessId);

        // Las fotos van a todos. Antes sólo a quien mandaba `X-Goveo-Supports:
        // image`, porque las apps anteriores a las fotos las pintaban como un
        // rectángulo negro; ya están todas actualizadas, así que la cabecera
        // sobra (los clientes la siguen mandando y no pasa nada).

        // Con sesión, fuera lo de las cuentas que ha bloqueado (y, con
        // `following`, sólo lo de las que sigue).
        $viewerId = $this->currentUser->currentId();

        $findFeed = fn () => $this->repository->findFeed(
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
            includeUnverified: $includeUnverified,
            subcategory:   $subcategory,
            viewerId:      $viewerId,
            exclude:       $exclude,
            eventDay:      $eventDay,
            subtype:       $subtype,
            following:     $following,
        );

        $result = $findFeed();

        // Self-heal: on an owner-scoped view (a store/influencer profile) any
        // still-`processing` upload is reconciled against Bunny, so a missed
        // webhook (e.g. a dead dev tunnel) doesn't leave it stuck. The webhook
        // remains the primary, immediate path; this is the fallback on read.
        $ownerScoped = $businessId !== null || $influencerId !== null;
        if ($ownerScoped && $this->reconcileProcessing($result['items'])) {
            $result = $findFeed();
        }

        return new JsonResponse([
            'items' => array_map(
                fn (GeoStoryWithDistance $s) => GeoStoryFeedSerializer::serialize(
                    $s,
                    $s->providerVideoId !== null ? ($this->progress[$s->providerVideoId] ?? null) : null,
                ),
                $result['items'],
            ),
            'total' => $result['total'],
        ]);
    }

    /**
     * Checks each still-`processing` item against Bunny and flips the DB row to
     * ready/failed when transcoding is done. Returns true if anything changed
     * (so the caller can re-query for fresh URLs/status).
     *
     * @param GeoStoryWithDistance[] $items
     */
    private function reconcileProcessing(array $items): bool
    {
        $changed = false;
        $this->progress = [];
        foreach ($items as $s) {
            if ($s->status !== 'processing' || $s->providerVideoId === null) {
                continue;
            }
            $state = $this->bunny->getVideoState($s->providerVideoId);
            if ($state === null) {
                continue;
            }
            $bunnyStatus = $state['status'];
            // Lo que lleva codificado, para la tarjeta: «Procesando… 45 %».
            if ($state['progress'] !== null) {
                $this->progress[$s->providerVideoId] = $state['progress'];
            }
            $entity = $this->repository->findByProviderVideoId($s->providerVideoId);
            if ($entity === null) {
                continue;
            }
            if ($bunnyStatus === self::BUNNY_FINISHED) {
                // Igual que en el webhook: la calidad real no se sabe hasta que
                // termina de codificar. Ver BunnyVideoService::getBestVideoUrl.
                $entity->setUrl($this->bunny->getBestVideoUrl($s->providerVideoId));
                $entity->markReady();
                $this->repository->save($entity);
                $changed = true;

                // También desde aquí: este camino existe justo para cuando el
                // webhook no ha llegado, así que es el único que se entera de
                // que el vídeo ya se puede revisar.
                $this->reviewQueue->geoStoryPendingReview($entity);
            } elseif ($bunnyStatus === self::BUNNY_FAILED) {
                $entity->markFailed();
                $this->repository->save($entity);
                $changed = true;
            }
        }

        return $changed;
    }
}
