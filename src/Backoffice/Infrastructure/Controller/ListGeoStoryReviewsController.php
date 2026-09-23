<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /api/admin/geostories?status=pending|scraped|verified|removed
 *                          &business=&q=&city=&category=&sort=&dir=&page=&size=
 *
 * La cola de vídeos. **`verified_at` ya decidía la visibilidad** —el repositorio
 * de geostories deja fuera de todos los feeds lo que no está verificado, y sólo
 * su dueño lo ve en su perfil—, pero `GeoStory::verify()` no la llamaba nadie:
 * el webhook de Bunny marca `ready` y ahí acababa la cosa. Todo lo subido desde
 * la app se quedaba invisible sin que nadie pudiera aprobarlo salvo a mano
 * contra la base.
 *
 * De ahí los tres estados:
 *
 * | Estado   | Condición                 | Qué significa                |
 * |----------|---------------------------|------------------------------|
 * | pending  | `verified_at IS NULL`     | subido y esperando; no se ve |
 * | scraped  | ídem, con `external_ref`  | importado por el scraping    |
 * | verified | `verified_at IS NOT NULL` | validado, y por eso se ve    |
 * | removed  | `deleted_at IS NOT NULL`  | borrado por su dueño         |
 *
 * `status` de la columna es otra cosa —cómo va la codificación en Bunny— y viaja
 * aparte: un vídeo en `processing` todavía no se puede ver para juzgarlo.
 *
 * **Filtrar y ordenar se hacen aquí**, sobre el total y no sobre las veinticuatro
 * tarjetas que hay en pantalla: ordenar una página ordena veinticuatro vídeos
 * cualesquiera, que no es lo que nadie quiere saber.
 *
 * **La ciudad es la del negocio**, no la del vídeo. Un vídeo tiene sus propias
 * coordenadas —donde se grabó—, pero quien filtra aquí viene de «los vídeos de
 * tal sitio», y eso es el negocio dueño. Como consecuencia, **filtrar por ciudad
 * deja fuera los de influencers**: no son de ningún negocio, así que no están en
 * ninguna de estas ciudades.
 */
#[Route('/api/admin/geostories', name: 'admin_geostories_list', methods: ['GET'])]
#[IsGranted('ROLE_GEOSTORY_MODERATE')]
class ListGeoStoryReviewsController
{
    private const DEFAULT_SIZE = 24;
    private const MAX_SIZE     = 100;

    /**
     * Por qué se puede ordenar. Lista blanca: el valor llega por query y acaba
     * dentro de un `ORDER BY`, donde no hay parámetros que valgan.
     */
    private const SORTS = [
        'created'  => 'g.created_at',
        'category' => 'c.name',
        'owner'    => 'coalesce(b.name, i.name)',
    ];

    public function __construct(
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request): Response
    {
        $status = (string) $request->query->get('status', 'pending');
        $page   = max(1, (int) $request->query->get('page', 1));
        $size   = min(self::MAX_SIZE, max(1, (int) $request->query->get('size', self::DEFAULT_SIZE)));
        $q      = trim((string) $request->query->get('q', ''));
        // Los de un negocio concreto: es como se llega desde su ficha.
        $business = trim((string) $request->query->get('business', ''));

        // Lo importado por el scraping tiene su propia pestaña: entra de cien en
        // cien, y en la misma cola enterraría lo que sube la gente, que es lo
        // que tiene a alguien esperando respuesta.
        $condition = match ($status) {
            'pending'  => 'g.deleted_at IS NULL AND g.verified_at IS NULL AND g.external_ref IS NULL',
            'scraped'  => 'g.deleted_at IS NULL AND g.verified_at IS NULL AND g.external_ref IS NOT NULL',
            'verified' => 'g.deleted_at IS NULL AND g.verified_at IS NOT NULL',
            'removed'  => 'g.deleted_at IS NOT NULL',
            default    => null,
        };

        if ($condition === null) {
            return new JsonResponse(
                ['error' => 'Unknown status. Use pending, scraped, verified or removed.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $where  = $condition;
        $params = [];

        if ($business !== '') {
            $where   .= ' AND g.business_id = ?';
            $params[] = $business;
        }

        // La ciudad del negocio dueño, y `none` para los que no la tienen
        // resuelta —o no son de un negocio—, que si no desaparecerían del panel
        // en cuanto alguien filtra.
        $city = trim((string) $request->query->get('city', ''));
        if ($city !== '') {
            if ($city === 'none') {
                $where .= ' AND b.city IS NULL';
            } else {
                $where   .= ' AND b.city = ?';
                $params[] = $city;
            }
        }

        // Por slug o por id, como el filtro público.
        $category = trim((string) $request->query->get('category', ''));
        if ($category !== '') {
            $where   .= ' AND (c.slug = ? OR c.id::text = ?)';
            $params[] = $category;
            $params[] = $category;
        }

        if ($q !== '') {
            // Por título y por el nombre de quien lo subió: quien busca aquí
            // suele venir de una queja sobre «los vídeos de tal sitio».
            $where .= ' AND (unaccent(lower(coalesce(g.title, \'\'))) LIKE unaccent(lower(?))
                          OR unaccent(lower(coalesce(b.name, \'\'))) LIKE unaccent(lower(?))
                          OR unaccent(lower(coalesce(i.name, \'\'))) LIKE unaccent(lower(?)))';
            // Se añaden, no se sustituyen: antes esto machacaba `$params`, que
            // cuando el único filtro era la búsqueda daba igual y ahora se
            // llevaría por delante la ciudad y la categoría.
            $params = [...$params, ...array_fill(0, 3, '%' . $q . '%')];
        }

        $from = 'FROM geostories g
            LEFT JOIN business b    ON b.id = g.business_id
            LEFT JOIN influencers i ON i.id = g.influencer_id
            LEFT JOIN categories c  ON c.id = g.category_id';

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) {$from} WHERE {$where}", $params);

        $rows = $this->db->fetchAllAssociative(
            "SELECT g.id, g.title, g.description, g.category_id, g.thumbnail, g.url,
                    g.status, g.media_type, g.meta, g.likes, g.views, g.external_ref,
                    g.created_at, g.verified_at, g.deleted_at, g.started_at, g.ended_at,
                    c.slug AS category_slug, c.name AS category_name,
                    b.id AS business_id, b.name AS business_name, b.avatar AS business_avatar,
                    i.id AS influencer_id, i.name AS influencer_name, i.avatar AS influencer_avatar
               {$from}
              WHERE {$where}
              ORDER BY " . $this->order($request) . "
              LIMIT ? OFFSET ?",
            [...$params, $size, ($page - 1) * $size],
        );

        return new JsonResponse([
            'items' => array_map([$this, 'toItem'], $rows),
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
        ]);
    }

    private static function meta(?string $raw): array
    {
        $meta = $raw !== null ? json_decode($raw, true) : null;

        return is_array($meta) ? $meta : [];
    }

    private static function linkUrl(?string $raw): ?string
    {
        $url = self::meta($raw)['link_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    private static function linkAction(?string $raw): ?string
    {
        if (self::linkUrl($raw) === null) {
            return null;
        }

        $action = self::meta($raw)['link_action'] ?? null;

        return in_array($action, ['buy', 'book', 'info'], true) ? $action : 'info';
    }

    private function toItem(array $row): array
    {
        // Un vídeo es de un negocio o de un influencer, nunca de los dos. El
        // panel sólo necesita saber a quién enseñar y adónde enlazar.
        $owner = $row['business_id'] !== null
            ? ['type' => 'business', 'id' => $row['business_id'], 'name' => $row['business_name'], 'avatar' => $row['business_avatar']]
            : ($row['influencer_id'] !== null
                ? ['type' => 'influencer', 'id' => $row['influencer_id'], 'name' => $row['influencer_name'], 'avatar' => $row['influencer_avatar']]
                : null);

        return [
            'id'          => $row['id'],
            'title'       => $row['title'],
            // Para poder editarlo sin una segunda petición: la ficha de un vídeo
            // es tan corta que traerla aparte sólo añadiría espera.
            'description' => $row['description'],
            'category_id' => $row['category_id'],
            'thumbnail'   => $row['thumbnail'],
            'url'       => $row['url'],
            // Cómo va la codificación en Bunny: en `processing` no hay nada que
            // mirar todavía, y aprobarlo a ciegas es aprobar cualquier cosa.
            'encoding'  => $row['status'],
            // Vídeo o foto: el panel monta un reproductor o una imagen, y
            // montar un reproductor sobre un JPEG no enseña nada.
            'media_type' => $row['media_type'] ?? 'video',
            // El enlace externo que acompaña a la publicación, plano como en el
            // producto. Vive en `meta` porque no es de nuestro dominio.
            'link_url'   => self::linkUrl($row['meta'] ?? null),
            'link_action' => self::linkAction($row['meta'] ?? null),
            // De qué pasada del scraping vino (`scraping_2026-09-23`); nulo en lo
            // que sube la gente.
            'origin'       => self::meta($row['meta'] ?? null)['origin'] ?? null,
            'external_ref' => $row['external_ref'] ?? null,
            'likes'     => (int) $row['likes'],
            'views'     => (int) $row['views'],
            'category'  => $row['category_slug'] === null ? null : [
                'slug' => $row['category_slug'],
                'name' => $row['category_name'],
            ],
            'owner'       => $owner,
            'created_at'  => self::iso($row['created_at']),
            'verified_at' => self::iso($row['verified_at']),
            'deleted_at'  => self::iso($row['deleted_at']),
            'started_at'  => self::iso($row['started_at']),
            'ended_at'    => self::iso($row['ended_at']),
        ];
    }

    /**
     * El `ORDER BY`.
     *
     * Por defecto, lo más reciente primero, también en la cola. Lo natural sería
     * atender antes a quien lleva más tiempo esperando, pero aquí los que llevan
     * más tiempo son los 137 importados de 2023 que nadie va a revisar: con ese
     * orden, un vídeo subido ayer aparecía en la página 28 y no se veía nunca.
     *
     * Un `sort` desconocido se ignora en lugar de responder un error: viaja en la
     * URL del panel y un enlace guardado tiene que seguir abriendo la lista.
     */
    private function order(Request $request): string
    {
        $sort = (string) $request->query->get('sort', '');

        if (!isset(self::SORTS[$sort])) {
            return 'g.created_at DESC';
        }

        $direction = strtolower((string) $request->query->get('dir', 'asc')) === 'desc' ? 'DESC' : 'ASC';

        // Los sin categoría al final ordene como ordene, y el id de desempate
        // para que el orden sea estable al pasar de página.
        return sprintf('%s %s NULLS LAST, g.id ASC', self::SORTS[$sort], $direction);
    }

    private static function iso(?string $timestamp): ?string
    {
        return $timestamp === null ? null : (new \DateTimeImmutable($timestamp))->format(\DATE_ATOM);
    }
}
