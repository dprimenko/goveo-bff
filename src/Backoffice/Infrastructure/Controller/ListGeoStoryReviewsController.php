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
 * GET /api/admin/geostories?status=pending|verified|removed&business=&q=&page=&size=
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
 * | verified | `verified_at IS NOT NULL` | validado, y por eso se ve    |
 * | removed  | `deleted_at IS NOT NULL`  | borrado por su dueño         |
 *
 * `status` de la columna es otra cosa —cómo va la codificación en Bunny— y viaja
 * aparte: un vídeo en `processing` todavía no se puede ver para juzgarlo.
 */
#[Route('/api/admin/geostories', name: 'admin_geostories_list', methods: ['GET'])]
#[IsGranted('ROLE_GEOSTORY_MODERATE')]
class ListGeoStoryReviewsController
{
    private const DEFAULT_SIZE = 24;
    private const MAX_SIZE     = 100;

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

        $condition = match ($status) {
            'pending'  => 'g.deleted_at IS NULL AND g.verified_at IS NULL',
            'verified' => 'g.deleted_at IS NULL AND g.verified_at IS NOT NULL',
            'removed'  => 'g.deleted_at IS NOT NULL',
            default    => null,
        };

        if ($condition === null) {
            return new JsonResponse(
                ['error' => 'Unknown status. Use pending, verified or removed.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $where  = $condition;
        $params = [];

        if ($business !== '') {
            $where   .= ' AND g.business_id = ?';
            $params[] = $business;
        }

        if ($q !== '') {
            // Por título y por el nombre de quien lo subió: quien busca aquí
            // suele venir de una queja sobre «los vídeos de tal sitio».
            $where .= ' AND (unaccent(lower(coalesce(g.title, \'\'))) LIKE unaccent(lower(?))
                          OR unaccent(lower(coalesce(b.name, \'\'))) LIKE unaccent(lower(?))
                          OR unaccent(lower(coalesce(i.name, \'\'))) LIKE unaccent(lower(?)))';
            $params = array_fill(0, 3, '%' . $q . '%');
        }

        $from = 'FROM geostories g
            LEFT JOIN business b    ON b.id = g.business_id
            LEFT JOIN influencers i ON i.id = g.influencer_id
            LEFT JOIN categories c  ON c.id = g.category_id';

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) {$from} WHERE {$where}", $params);

        $rows = $this->db->fetchAllAssociative(
            "SELECT g.id, g.title, g.thumbnail, g.url, g.status, g.likes, g.views,
                    g.created_at, g.verified_at, g.deleted_at, g.started_at, g.ended_at,
                    c.slug AS category_slug, c.name AS category_name,
                    b.id AS business_id, b.name AS business_name, b.avatar AS business_avatar,
                    i.id AS influencer_id, i.name AS influencer_name, i.avatar AS influencer_avatar
               {$from}
              WHERE {$where}
              -- Lo más antiguo primero en la cola, por lo mismo que en negocios:
              -- quien lleva más tiempo esperando es a quien peor se le atiende.
              ORDER BY g.created_at " . ($status === 'pending' ? 'ASC' : 'DESC') . '
              LIMIT ? OFFSET ?',
            [...$params, $size, ($page - 1) * $size],
        );

        return new JsonResponse([
            'items' => array_map([$this, 'toItem'], $rows),
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
        ]);
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
            'id'        => $row['id'],
            'title'     => $row['title'],
            'thumbnail' => $row['thumbnail'],
            'url'       => $row['url'],
            // Cómo va la codificación en Bunny: en `processing` no hay nada que
            // mirar todavía, y aprobarlo a ciegas es aprobar cualquier cosa.
            'encoding'  => $row['status'],
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

    private static function iso(?string $timestamp): ?string
    {
        return $timestamp === null ? null : (new \DateTimeImmutable($timestamp))->format(\DATE_ATOM);
    }
}
