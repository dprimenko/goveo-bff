<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Repository;

use App\GeoStories\Domain\EventDay;
use App\GeoStories\Domain\GeoStory;
use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Domain\GeoStoryWithDistance;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineGeoStoryRepository implements GeoStoryRepository
{
    /**
     * Columnas y joins del feed, compartidos con Guardados: los dos devuelven
     * `GeoStoryWithDistance` y la app los pasa por el mismo mapper.
     * `:lat`/`:lng` son siempre parámetros de la consulta.
     */
    private const FEED_SELECT = <<<'SQL'
            SELECT
                geo.id,
                geo.title,
                geo.description,
                geo.thumbnail,
                geo.url,
                geo.status,
                geo.media_type,
                geo.provider_video_id,
                geo.meta,
                (geo.likes + COALESCE(gl.c, 0))                                          AS likes,
                geo.started_at,
                geo.ended_at,
                geo.created_at,
                geo.verified_at,
                geo.deleted_at,
                ST_Y(geo.location::geometry)                                                        AS lat,
                ST_X(geo.location::geometry)                                                        AS long,
                ST_Distance(geo.location, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography)   AS dist_meters,
                influ.id     AS influencer_id,
                influ.name   AS influencer_name,
                influ.avatar AS influencer_avatar,
                buss.id      AS business_id,
                buss.name    AS business_name,
                buss.avatar  AS business_avatar,
                buss.meta    AS business_meta,
                cat.id       AS category_id,
                cat.name     AS category_name,
                cat.slug     AS category_slug,
                sub.id       AS subcategory_id,
                sub.slug     AS subcategory_slug,
                sub.name     AS subcategory_name,
                COUNT(*) OVER() AS total_count
            FROM geostories geo
            LEFT JOIN influencers influ ON geo.influencer_id = influ.id
            LEFT JOIN business    buss  ON geo.business_id   = buss.id
            LEFT JOIN categories  cat   ON geo.category_id   = cat.id
            LEFT JOIN categories  sub   ON geo.subcategory_id = sub.id
            LEFT JOIN categories  grp   ON cat.parent_id     = grp.id
            -- likes = base heredada del import + likes nuevos con usuario
            LEFT JOIN (
                SELECT geostory_id, COUNT(*)::int AS c FROM geostory_likes GROUP BY geostory_id
            ) gl ON gl.geostory_id = geo.id
        SQL;

    public function __construct(
        private readonly EntityManagerInterface $em,
        /**
         * Si los eventos y las noticias caducan de verdad.
         *
         * Es un interruptor y no una constante para poder apagarlo **sin
         * publicar una versión de la app**: la regla vive aquí, así que se
         * cambia en el entorno y se reinicia. Apagado, todo se ve pase el
         * tiempo que pase; las fechas se siguen guardando igual, así que
         * volver a encenderlo no pierde nada.
         */
        private readonly bool $expiryEnabled = true,
    ) {}

    public function findById(string $id): ?GeoStory
    {
        return $this->em->find(GeoStory::class, $id);
    }

    public function findByProviderVideoId(string $providerVideoId): ?GeoStory
    {
        return $this->em->getRepository(GeoStory::class)->findOneBy([
            'providerVideoId' => $providerVideoId,
            'deletedAt'       => null,
        ]);
    }

    public function findStuckProcessing(int $limit = 200): array
    {
        return $this->em->createQueryBuilder()
            ->select('g')
            ->from(GeoStory::class, 'g')
            ->where('g.status = :processing')
            ->andWhere('g.deletedAt IS NULL')
            ->andWhere('g.providerVideoId IS NOT NULL')
            ->setParameter('processing', GeoStory::STATUS_PROCESSING)
            // Del más viejo al más nuevo: el que lleva días esperando es el que
            // hay que mirar, no el que acaba de subirse y está bien.
            ->orderBy('g.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByInfluencerId(string $influencerId): array
    {
        return $this->em->getRepository(GeoStory::class)->findBy(
            ['influencerId' => $influencerId, 'deletedAt' => null],
            ['createdAt' => 'DESC'],
        );
    }

    public function findByBusinessId(string $businessId): array
    {
        return $this->em->getRepository(GeoStory::class)->findBy(
            ['businessId' => $businessId, 'deletedAt' => null],
            ['createdAt' => 'DESC'],
        );
    }

    public function findByCategoryId(string $categoryId): array
    {
        return $this->em->getRepository(GeoStory::class)->findBy(
            ['categoryId' => $categoryId, 'deletedAt' => null],
            ['createdAt' => 'DESC'],
        );
    }

    public function findNearby(float $latitude, float $longitude, float $radiusMeters, int $limit = 20): array
    {
        $sql = <<<'SQL'
            SELECT g.*
            FROM geostories g
            WHERE g.deleted_at IS NULL
              AND g.location IS NOT NULL
              AND ST_DWithin(
                    g.location::geography,
                    ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography,
                    :radius
                  )
            ORDER BY g.location <-> ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography
            LIMIT :limit
        SQL;

        $conn = $this->em->getConnection();
        $result = $conn->executeQuery($sql, [
            'lat' => $latitude,
            'lng' => $longitude,
            'radius' => $radiusMeters,
            'limit' => $limit,
        ]);

        $rows = $result->fetchAllAssociative();
        $ids = array_column($rows, 'id');

        if (empty($ids)) {
            return [];
        }

        return $this->em->createQueryBuilder()
            ->select('g')
            ->from(GeoStory::class, 'g')
            ->where('g.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /** @return GeoStoryWithDistance[] */
    public function findNearbyWithDetails(
        float $latitude,
        float $longitude,
        ?float $maxDistMeters = null,
        ?string $ignoreId = null,
        int $limit = 50,
    ): array {
        $sql = <<<'SQL'
            SELECT
                geo.id,
                geo.title,
                geo.description,
                geo.thumbnail,
                geo.url,
                geo.status,
                geo.media_type,
                geo.meta,
                (geo.likes + COALESCE(gl.c, 0))                                          AS likes,
                geo.started_at,
                geo.ended_at,
                geo.created_at,
                geo.verified_at,
                geo.deleted_at,
                ST_Y(geo.location::geometry)                                            AS lat,
                ST_X(geo.location::geometry)                                            AS long,
                ST_Distance(geo.location, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography) AS dist_meters,
                influ.id   AS influencer_id,
                influ.name AS influencer_name,
                influ.avatar AS influencer_avatar,
                buss.id    AS business_id,
                buss.name  AS business_name,
                buss.avatar AS business_avatar,
                buss.meta  AS business_meta,
                cat.id     AS category_id,
                cat.name   AS category_name,
                cat.slug   AS category_slug,
                sub.id     AS subcategory_id,
                sub.slug   AS subcategory_slug,
                sub.name   AS subcategory_name
            FROM geostories geo
            LEFT JOIN influencers influ ON geo.influencer_id = influ.id
            LEFT JOIN business     buss ON geo.business_id   = buss.id
            LEFT JOIN categories   cat  ON geo.category_id   = cat.id
            LEFT JOIN categories   sub  ON geo.subcategory_id = sub.id
            -- likes = base heredada del import + likes nuevos con usuario
            LEFT JOIN (
                SELECT geostory_id, COUNT(*)::int AS c FROM geostory_likes GROUP BY geostory_id
            ) gl ON gl.geostory_id = geo.id
            WHERE geo.deleted_at IS NULL
              AND (:ignore_id::uuid IS NULL OR geo.id <> :ignore_id::uuid)
              AND (:max_dist IS NULL OR
                   ST_Distance(geo.location, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography) <= :max_dist)
            ORDER BY geo.location <-> ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography
            LIMIT :limit
        SQL;

        $conn = $this->em->getConnection();
        $rows = $conn->executeQuery($sql, [
            'lat'       => $latitude,
            'lng'       => $longitude,
            'ignore_id' => $ignoreId,
            'max_dist'  => $maxDistMeters,
            'limit'     => $limit,
        ])->fetchAllAssociative();

        return array_map(GeoStoryWithDistance::fromRow(...), $rows);
    }

    public function findByIdWithDistance(string $id, float $latitude, float $longitude): ?GeoStoryWithDistance
    {
        $sql = <<<'SQL'
            SELECT
                geo.id,
                geo.title,
                geo.description,
                geo.thumbnail,
                geo.url,
                geo.status,
                geo.media_type,
                geo.meta,
                (geo.likes + COALESCE(gl.c, 0))                                          AS likes,
                geo.started_at,
                geo.ended_at,
                geo.created_at,
                geo.verified_at,
                geo.deleted_at,
                ST_Y(geo.location::geometry)                                            AS lat,
                ST_X(geo.location::geometry)                                            AS long,
                ST_Distance(geo.location, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography) AS dist_meters,
                influ.id   AS influencer_id,
                influ.name AS influencer_name,
                influ.avatar AS influencer_avatar,
                buss.id    AS business_id,
                buss.name  AS business_name,
                buss.avatar AS business_avatar,
                buss.meta  AS business_meta,
                cat.id     AS category_id,
                cat.name   AS category_name,
                cat.slug   AS category_slug,
                sub.id     AS subcategory_id,
                sub.slug   AS subcategory_slug,
                sub.name   AS subcategory_name
            FROM geostories geo
            LEFT JOIN influencers influ ON geo.influencer_id = influ.id
            LEFT JOIN business     buss ON geo.business_id   = buss.id
            LEFT JOIN categories   cat  ON geo.category_id   = cat.id
            LEFT JOIN categories   sub  ON geo.subcategory_id = sub.id
            -- likes = base heredada del import + likes nuevos con usuario
            LEFT JOIN (
                SELECT geostory_id, COUNT(*)::int AS c FROM geostory_likes GROUP BY geostory_id
            ) gl ON gl.geostory_id = geo.id
            WHERE geo.id = :id
        SQL;

        $conn = $this->em->getConnection();
        $row = $conn->executeQuery($sql, [
            'id'  => $id,
            'lat' => $latitude,
            'lng' => $longitude,
        ])->fetchAssociative();

        return $row !== false ? GeoStoryWithDistance::fromRow($row) : null;
    }

    public function findFeed(
        float $latitude,
        float $longitude,
        int $page = 0,
        int $size = 10,
        ?float $maxDistMeters = null,
        ?string $ignoreId = null,
        ?string $feedType = null,
        ?string $categoryId = null,
        ?string $notCategoryId = null,
        ?string $businessId = null,
        ?string $influencerId = null,
        bool $includeUnverified = false,
        ?string $subcategory = null,
        ?string $viewerId = null,
        ?string $exclude = null,
        ?EventDay $eventDay = null,
        ?string $subtype = null,
        bool $following = false,
    ): array {
        // «Siguiendo» sin sesión no tiene a quién seguir: vacío, sin consultar.
        if ($following && $viewerId === null) {
            return ['items' => [], 'total' => 0];
        }

        $conditions = [
            'geo.deleted_at IS NULL',
        ];
        $params = [
            'lat'      => $latitude,
            'lng'      => $longitude,
            'limit'    => $size,
            'offset'   => $page * $size,
        ];

        if ($ignoreId !== null) {
            $conditions[] = 'geo.id <> :ignore_id::uuid';
            $params['ignore_id'] = $ignoreId;
        }

        if ($maxDistMeters !== null) {
            $conditions[] = 'ST_Distance(geo.location, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography) <= :max_dist';
            $params['max_dist'] = $maxDistMeters;
        }

        if ($businessId !== null) {
            $conditions[] = 'geo.business_id = :business_id::uuid';
            $params['business_id'] = $businessId;
        }

        if ($influencerId !== null) {
            $conditions[] = 'geo.influencer_id = :influencer_id::uuid';
            $params['influencer_id'] = $influencerId;
        }

        // Discovery feeds only show fully transcoded videos; an owner-scoped
        // query (a store/influencer profile) also surfaces its own in-progress
        // uploads so they appear immediately in "processing" state.
        $isOwnerScoped = $businessId !== null || $influencerId !== null;
        if (!$isOwnerScoped) {
            $conditions[] = "geo.status = 'ready'";
        }

        // Un vídeo sin revisar no es público: no sale en los feeds ni en el
        // perfil que visita otro. Sólo su dueño lo ve, y para eso el que
        // pregunta tiene que haberse identificado como tal.
        if (!$includeUnverified) {
            $conditions[] = 'geo.verified_at IS NOT NULL';

            // Los eventos de una sala que creó el scraping no se ven hasta que
            // se valida la sala: llevarían a una ficha que todavía no es
            // pública. Sólo en esas —con `external_ref`—: el resto de negocios
            // sin validar siguen como estaban, y cambiarlos escondería vídeos ya
            // aprobados de negocios reales que esperan revisión.
            $conditions[] = 'NOT (buss.external_ref IS NOT NULL AND buss.verified_at IS NULL)';
        }

        // Lo de las cuentas que ha bloqueado quien mira no sale en ningún
        // sitio, tampoco en su perfil si llega a él por un enlace: bloquear es
        // no volver a ver lo que publica (Apple, guideline 1.2).
        if ($viewerId !== null) {
            $conditions[] = "NOT EXISTS (
                SELECT 1 FROM user_blocks ub
                 WHERE ub.user_id::text = :viewer_id
                   AND ((ub.target_type = 'business'   AND ub.target_id = geo.business_id)
                     OR (ub.target_type = 'influencer' AND ub.target_id = geo.influencer_id)))";
            $params['viewer_id'] = $viewerId;

            // «Siguiendo»: el mismo cruce que el bloqueo, al revés. Se suma a
            // todo lo demás (tipo de feed, tipo de evento, día, distancia…).
            if ($following) {
                $conditions[] = "EXISTS (
                    SELECT 1 FROM user_follows uf
                     WHERE uf.user_id::text = :viewer_id
                       AND ((uf.target_type = 'business'   AND uf.target_id = geo.business_id)
                         OR (uf.target_type = 'influencer' AND uf.target_id = geo.influencer_id)))";
            }
        }

        // Feed-type category filters use cat.slug via the categories JOIN.
        // category_id in geostories is a UUID — never compare it against slug strings directly.
        // Los feeds de descubrimiento ordenan por cercanía; el perfil de un
        // negocio o un influencer, por fecha —lo último que ha subido primero—,
        // que es como lo lee quien entra a ver a alguien.
        $orderBy = $isOwnerScoped
            ? 'geo.created_at DESC'
            : 'geo.location <-> ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography';

        // ── Vigencia: quién sigue vivo ──────────────────────────────────────
        //
        // Eventos y noticias caducan (ver `StorySchedule`); el resto, no. Se
        // aplica **siempre** y no sólo en su feed: un evento que ya ha
        // terminado tampoco tiene que seguir en el perfil de quien lo subió.
        //
        // Lo único que cambia entre sitios es la antelación. En el feed un
        // evento asoma un mes antes de empezar —antes sería anunciar algo que
        // no le sirve todavía a nadie—, pero en el perfil de su dueño se ve
        // desde que lo sube, aunque sea para dentro de medio año: ahí es su
        // catálogo, no un descubrimiento.
        //
        // Los eventos comparan contra columnas y no contra cuentas al vuelo:
        // los importados ya llevan sus dos fechas escritas (ver la migración
        // Version20260903170000). Un evento sin fin no se ve, y es correcto —
        // sin él no hay forma de saber cuándo deja de valer.
        //
        // Las noticias sí conservan el respaldo: quedan 71 importadas sin
        // fechas, y ahí `created_at` es la única referencia de cuándo fueron
        // noticia.
        //
        // La fila de Eventos de un perfil (`feedType=events` con el creador)
        // sigue la regla del feed para quien lo visita: lo mismo que vería en la
        // pestaña. Su dueño, en cambio, los ve todos, o el evento que acaba de
        // subir para dentro de seis meses desaparecería de su perfil.
        $ownerView   = $isOwnerScoped && ($feedType !== 'events' || $includeUnverified);
        $eventLeadIn = $ownerView
            ? 'TRUE'
            : "NOW() >= geo.started_at - INTERVAL '1 month'";

        // Con un día elegido en la pestaña de Eventos, lo que está en marcha ese
        // día (desde la hora, si la hay) y no lo de hoy. Sin el «mes vista»:
        // quien elige un día de diciembre quiere ver diciembre. Ver `EventDay`.
        // Va aparte de la caducidad, que puede estar apagada (en local lo está):
        // elegir un día tiene que filtrar siempre.
        $eventWindow = "{$eventLeadIn} AND NOW() <= geo.ended_at";
        if ($eventDay !== null && $feedType === 'events') {
            // Un evento de menos de 24 h es del día en que empieza: el concierto
            // del día 2 de 21:00 a 00:00 (o a las 02:00) es la noche del 2, no
            // un plan del 3. Los de 24 h o más salen todos los días que abarcan.
            $conditions[] = "geo.started_at < :ev_day_end AND geo.ended_at >= :ev_from
                AND (geo.ended_at - geo.started_at >= INTERVAL '24 hours' OR geo.started_at >= :ev_day_start)";
            $params['ev_day_end']   = $eventDay->dayEnd->format('Y-m-d H:i:sP');
            $params['ev_from']      = $eventDay->from->format('Y-m-d H:i:sP');
            $params['ev_day_start'] = $eventDay->dayStart->format('Y-m-d H:i:sP');
            $eventWindow = 'TRUE';
        }

        if ($this->expiryEnabled) {
            $conditions[] = "(CASE cat.slug
                WHEN 'events' THEN {$eventWindow}
                WHEN 'news' THEN NOW() BETWEEN COALESCE(geo.started_at, geo.created_at)
                    AND COALESCE(
                        geo.ended_at,
                        COALESCE(geo.started_at, geo.created_at) + INTERVAL '7 days'
                    )
                ELSE TRUE
            END)";
        }

        if ($feedType === 'events' && $categoryId === null) {
            $conditions[] = "cat.slug = 'events'";
            // Lo que antes empieza, primero: en un feed de eventos la fecha
            // manda sobre la cercanía. Los recurrentes, intercalados: ver
            // `EventFeedOrder`.
            // «Antes de hoy» de los recurrentes es «antes del día elegido».
            if ($eventDay !== null) {
                $params['ev_day_start'] = $eventDay->dayStart->format('Y-m-d H:i:sP');
            }
            $orderBy = EventFeedOrder::orderBy($eventDay !== null ? ':ev_day_start' : null);
        } elseif ($feedType === 'geostories' && $categoryId === null) {
            $conditions[] = "cat.slug = 'news'";
        } elseif ($feedType === 'tourism') {
            // Lo de influencers de siempre más lo que cuelga de un grupo de
            // Turismo (o es uno: lo que falta por clasificar) y el partner
            // ibiza — el mismo corte que `section` en `/public/businesses`.
            // Con una categoría encima se suman: filtrar Alojamientos dentro
            // de Turismo es filtrar Alojamientos.
            $conditions[] = "(cat.slug IN ('place', 'nature', 'culture')
                OR COALESCE(grp.section, cat.section) = 'tourism'
                OR cat.partner IS NOT NULL)";
        } elseif ($feedType === 'local') {
            $conditions[] = "cat.slug NOT IN ('place', 'events', 'news', 'culture', 'nature')";
            $conditions[] = "COALESCE(grp.section, cat.section, 'local') <> 'tourism'";
            $conditions[] = 'cat.partner IS NULL';
            if ($notCategoryId !== null) {
                $conditions[] = 'cat.slug != :not_cat';
                $params['not_cat'] = $notCategoryId;
            }
        }

        // La fila de vídeos de un perfil, sin los eventos: van en la suya.
        if ($exclude === 'events') {
            $conditions[] = "cat.slug IS DISTINCT FROM 'events'";
        }

        // Explicit category filter: accepts a UUID (from the category picker) or a slug.
        // La subcategoría de un evento, por slug o id («Todos los eventos» es
        // no mandarla).
        if ($subcategory !== null && $subcategory !== '') {
            $conditions[] = '(sub.id::text = :subcategory OR sub.slug = :subcategory)';
            $params['subcategory'] = $subcategory;
        }

        // Y su subnivel, dentro de ese tipo. Lo que no tiene subnivel sólo sale
        // en «Todos» del tipo: no se adivina a cuál pertenecería.
        if ($subtype !== null && $subtype !== '') {
            $conditions[] = 'geo.subtype_id IN (SELECT st.id FROM categories st WHERE st.id::text = :subtype OR st.slug = :subtype)';
            $params['subtype'] = $subtype;
        }

        if ($categoryId !== null) {
            // Un grupo trae lo de sus subcategorías: el vídeo lleva la hoja.
            $conditions[] = '(cat.id::text = :category_id OR cat.slug = :category_id
                OR grp.id::text = :category_id OR grp.slug = :category_id)';
            $params['category_id'] = $categoryId;
        }

        $where = implode(' AND ', $conditions);

        $select = self::FEED_SELECT;

        $sql = <<<SQL
            $select
            WHERE $where
            ORDER BY $orderBy
            LIMIT :limit OFFSET :offset
        SQL;

        $rows = $this->em->getConnection()
            ->executeQuery($sql, $params)
            ->fetchAllAssociative();

        $total = empty($rows) ? 0 : (int) $rows[0]['total_count'];

        return [
            'items' => array_map(GeoStoryWithDistance::fromRow(...), $rows),
            'total' => $total,
        ];
    }

    public function countEventsByType(
        ?EventDay $eventDay = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $maxDistMeters = null,
    ): array {
        $conditions = [
            'geo.deleted_at IS NULL',
            "geo.status = 'ready'",
            'geo.verified_at IS NOT NULL',
            'NOT (buss.external_ref IS NOT NULL AND buss.verified_at IS NULL)',
            "cat.slug = 'events'",
        ];
        $params = [];

        if ($latitude !== null && $longitude !== null && $maxDistMeters !== null) {
            $conditions[] = 'ST_Distance(geo.location, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography) <= :max_dist';
            $params += ['lat' => $latitude, 'lng' => $longitude, 'max_dist' => $maxDistMeters];
        }

        // La misma ventana que `findFeed`: con un día, lo de ese día; sin él,
        // lo vigente que ya asoma (un mes antes de empezar).
        if ($eventDay !== null) {
            $conditions[] = "geo.started_at < :ev_day_end AND geo.ended_at >= :ev_from
                AND (geo.ended_at - geo.started_at >= INTERVAL '24 hours' OR geo.started_at >= :ev_day_start)";
            $params['ev_day_end']   = $eventDay->dayEnd->format('Y-m-d H:i:sP');
            $params['ev_from']      = $eventDay->from->format('Y-m-d H:i:sP');
            $params['ev_day_start'] = $eventDay->dayStart->format('Y-m-d H:i:sP');
        } elseif ($this->expiryEnabled) {
            $conditions[] = "NOW() >= geo.started_at - INTERVAL '1 month' AND NOW() <= geo.ended_at";
        }

        $where = implode(' AND ', $conditions);

        // Cada evento cuenta en su tipo y en su subnivel.
        $rows = $this->em->getConnection()->fetchAllKeyValue(
            "SELECT x.id::text, COUNT(*)
               FROM geostories geo
               JOIN categories cat ON cat.id = geo.category_id
               LEFT JOIN business buss ON buss.id = geo.business_id
              CROSS JOIN LATERAL (VALUES (geo.subcategory_id), (geo.subtype_id)) AS x(id)
              WHERE $where AND x.id IS NOT NULL
              GROUP BY x.id",
            $params,
        );

        return array_map('intval', $rows);
    }

    public function findSavedBy(
        string $userId,
        float $latitude,
        float $longitude,
        int $page = 0,
        int $size = 10,
    ): array {
        // Lo mismo que vería en el feed —listo, validado, sin las salas del
        // scraping pendientes, sin lo bloqueado— pero sin caducidad: un evento
        // que ya pasó y se guardó sigue siendo suyo. Si se borra o se retira,
        // desaparece de aquí también (la fila se queda y vuelve si se restaura).
        $select = self::FEED_SELECT;

        $sql = <<<SQL
            $select
            JOIN saved_geostories sg ON sg.geostory_id = geo.id AND sg.user_id::text = :viewer_id
            WHERE geo.deleted_at IS NULL
              AND geo.status = 'ready'
              AND geo.verified_at IS NOT NULL
              AND NOT (buss.external_ref IS NOT NULL AND buss.verified_at IS NULL)
              AND NOT EXISTS (
                SELECT 1 FROM user_blocks ub
                 WHERE ub.user_id::text = :viewer_id
                   AND ((ub.target_type = 'business'   AND ub.target_id = geo.business_id)
                     OR (ub.target_type = 'influencer' AND ub.target_id = geo.influencer_id)))
            ORDER BY sg.created_at DESC, geo.id
            LIMIT :limit OFFSET :offset
        SQL;

        $rows = $this->em->getConnection()
            ->executeQuery($sql, [
                'lat'       => $latitude,
                'lng'       => $longitude,
                'viewer_id' => $userId,
                'limit'     => $size,
                'offset'    => $page * $size,
            ])
            ->fetchAllAssociative();

        return [
            'items' => array_map(GeoStoryWithDistance::fromRow(...), $rows),
            'total' => empty($rows) ? 0 : (int) $rows[0]['total_count'],
        ];
    }

    public function save(GeoStory $geoStory): void
    {
        $this->em->persist($geoStory);
        $this->em->flush();
    }

    public function delete(GeoStory $geoStory): void
    {
        $this->em->remove($geoStory);
        $this->em->flush();
    }
}
