<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Controller;

use App\Users\Infrastructure\Service\LocalUserResolver;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Negocios que gestiona el usuario — la pantalla «Gestión de negocios».
 *
 * `/api/auth/me` ya devuelve los ids, pero para pintar la lista hacen falta
 * nombre, portada y si están validados.
 *
 * **Paginado y con buscador.** Al principio devolvía la lista entera y una
 * consulta por negocio: con las cuentas de gestión, que llevan más de
 * cuatrocientas tiendas, eso son cuatrocientas consultas cada vez que se abre la
 * pantalla, y una lista por la que nadie puede desplazarse hasta encontrar la
 * suya. Ahora es una sola consulta y se busca por nombre.
 */
#[Route('/api/account/businesses', name: 'account_businesses', methods: ['GET'])]
class ListMyBusinessesController
{
    /** Lo que cabe de sobra en una pantalla larga; el resto llega al bajar. */
    private const DEFAULT_SIZE = 20;
    private const MAX_SIZE     = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly LocalUserResolver $currentUser,
    ) {}

    public function __invoke(Request $request): Response
    {
        $userId = $this->currentUser->currentId();

        if ($userId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $size = min(self::MAX_SIZE, max(1, (int) $request->query->get('size', self::DEFAULT_SIZE)));
        $q    = trim((string) $request->query->get('q', ''));

        $where  = 'bm.user_id = ? AND bm.deleted_at IS NULL AND b.deleted_at IS NULL';
        $params = [$userId];

        if ($q !== '') {
            // `unaccent` por lo mismo que en la búsqueda pública: quien busca
            // «jamoneria» espera encontrar «Jamonería».
            $where   .= ' AND unaccent(lower(b.name)) LIKE unaccent(lower(?))';
            $params[] = '%' . $q . '%';
        }

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM business_managers bm
               JOIN business b ON b.id = bm.business_id
              WHERE {$where}",
            $params
        );

        $rows = $this->db->fetchAllAssociative(
            "SELECT b.id, b.name, b.avatar, b.main_image, b.verified_at
               FROM business_managers bm
               JOIN business b ON b.id = bm.business_id
              WHERE {$where}
              -- Los pendientes de validar primero: son los que necesitan que su
              -- dueño haga algo, y en una lista larga nunca los encontraría.
              ORDER BY (b.verified_at IS NULL) DESC, b.name ASC
              LIMIT ? OFFSET ?",
            [...$params, $size, ($page - 1) * $size]
        );

        return new JsonResponse([
            'items' => array_map(
                static fn (array $b) => [
                    'id'         => $b['id'],
                    'name'       => $b['name'],
                    'avatar'     => $b['avatar'],
                    'main_image' => $b['main_image'],
                    // Sin `verified_at` la ficha está pendiente de validación.
                    'verified'   => $b['verified_at'] !== null,
                ],
                $rows,
            ),
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
        ]);
    }
}
