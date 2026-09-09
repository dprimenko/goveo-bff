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
 * GET /api/admin/businesses?status=pending|rejected|verified&q=&page=&size=
 *
 * La cola de revisión del panel. Tres estados que se excluyen entre sí:
 *
 * | Estado    | Condición                                  |
 * |-----------|--------------------------------------------|
 * | pending   | ni validado ni rechazado — nadie lo ha visto |
 * | rejected  | revisado y descartado                      |
 * | verified  | validado y a la vista                       |
 *
 * De cara al público, rechazado y pendiente son lo mismo: lo que decide si un
 * negocio se ve es `verified_at`. La diferencia existe para quien revisa, que si
 * no tendría que volver a mirar cada visita lo que ya descartó.
 *
 * Una sola consulta con los datos que hacen falta para decidir —quién es, dónde
 * está, con qué se dio de alta y a nombre de quién factura—, porque revisar una
 * ficha abriendo cinco pantallas no lo hace nadie.
 */
#[Route('/api/admin/businesses', name: 'admin_businesses_list', methods: ['GET'])]
#[IsGranted('ROLE_BUSINESS_VERIFY')]
class ListBusinessReviewsController
{
    private const DEFAULT_SIZE = 20;
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

        $condition = match ($status) {
            'rejected' => 'b.rejected_at IS NOT NULL',
            'verified' => 'b.verified_at IS NOT NULL',
            'pending'  => 'b.verified_at IS NULL AND b.rejected_at IS NULL',
            default    => null,
        };

        if ($condition === null) {
            return new JsonResponse(
                ['error' => 'Unknown status. Use pending, rejected or verified.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $where  = "b.deleted_at IS NULL AND ({$condition})";
        $params = [];

        if ($q !== '') {
            // `unaccent`, como en la búsqueda pública: quien escribe «jamoneria»
            // espera encontrar «Jamonería».
            $where   .= ' AND unaccent(lower(b.name)) LIKE unaccent(lower(?))';
            $params[] = '%' . $q . '%';
        }

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM business b WHERE {$where}",
            $params,
        );

        $rows = $this->db->fetchAllAssociative(
            "SELECT b.id, b.slug, b.name, b.avatar, b.main_image, b.meta,
                    b.created_at, b.verified_at, b.rejected_at,
                    c.slug AS category_slug, c.name AS category_name
               FROM business b
          LEFT JOIN categories c ON c.id = b.category_id
              WHERE {$where}
              -- Lo más antiguo primero: en una cola de revisión, quien lleva más
              -- tiempo esperando es a quien peor se le está atendiendo.
              ORDER BY b.created_at ASC
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

    private static function iso(?string $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return (new \DateTimeImmutable($timestamp))->format(\DATE_ATOM);
    }

    private function toItem(array $row): array
    {
        $meta    = json_decode((string) ($row['meta'] ?? ''), true) ?: [];
        $billing = $meta['billing'] ?? [];

        return [
            'id'         => $row['id'],
            'slug'       => $row['slug'],
            'name'       => $row['name'],
            'avatar'     => $row['avatar'],
            'main_image' => $row['main_image'],
            'category'   => $row['category_slug'] === null ? null : [
                'slug' => $row['category_slug'],
                // Clave de traducción, no un rótulo: se traduce en el panel.
                'name' => $row['category_name'],
            ],
            'address'    => $meta['address'] ?? null,
            'phone'      => $meta['public_phone'] ?? ($billing['phone'] ?? null),
            'website'    => $meta['website_url'] ?? null,
            // Con qué se dio de alta: es lo que se comprueba para validar.
            'billing'    => [
                'company_name' => $billing['company_name'] ?? null,
                'tax_id'       => $billing['tax_id'] ?? null,
                'email'        => $billing['email'] ?? null,
                'address'      => $billing['address'] ?? null,
            ],
            // En ISO 8601 y no como los devuelve Postgres («2026-09-04
            // 11:56:16+00»): ese formato no lo entiende el `Date` del navegador
            // —el desfase sin minutos no es válido— y las fechas salían vacías.
            'created_at'  => self::iso($row['created_at']),
            'verified_at' => self::iso($row['verified_at']),
            'rejected_at' => self::iso($row['rejected_at']),
        ];
    }
}
