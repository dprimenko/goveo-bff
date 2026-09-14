<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/admin/cities — las ciudades donde hay algún negocio.
 *
 * Lo que llena los dos desplegables del panel, el de negocios y el de vídeos. No
 * es un catálogo: **sale de los propios negocios**, así que sólo aparece una
 * ciudad si hay al menos uno en ella. Un desplegable con las ciudades de España
 * dejaría elegir cuarenta que no devuelven nada.
 *
 * Los borrados no cuentan: si el único negocio de un pueblo se archiva, ese
 * pueblo deja de estar en la lista — y quien lo tuviera puesto en la URL ve la
 * lista vacía, que es la verdad.
 *
 * Va con el número de negocios de cada una porque el orden es por cantidad y no
 * alfabético: con Madrid a un lado y noventa y cuatro pueblos al otro, lo útil
 * está arriba. El número además explica ese orden, que si no se lee como
 * desordenado.
 *
 * Sin `IsGranted` propio: `^/api/admin` ya exige `ROLE_BACKOFFICE_ACCESS`, y
 * pedir además el permiso de validar negocios dejaría la pantalla de vídeos sin
 * filtro para quien sólo modera vídeos.
 */
#[Route('/api/admin/cities', name: 'admin_cities_list', methods: ['GET'])]
class ListBusinessCitiesController
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function __invoke(): Response
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT city AS name, count(*) AS total
              FROM business
             WHERE deleted_at IS NULL AND city IS NOT NULL
          GROUP BY city
          ORDER BY count(*) DESC, city ASC
        SQL);

        // Los que aún no tienen ciudad, para que se puedan encontrar. Son los
        // que no tienen coordenadas y los que entraron después del último
        // `goveo:business:backfill-cities`; sin esta entrada desaparecerían del
        // panel en cuanto alguien filtra, sin decir que existen.
        $unknown = (int) $this->db->fetchOne(
            'SELECT count(*) FROM business WHERE deleted_at IS NULL AND city IS NULL',
        );

        return new JsonResponse([
            'items'   => array_map(static fn (array $r) => [
                'name'  => $r['name'],
                'total' => (int) $r['total'],
            ], $rows),
            'unknown' => $unknown,
        ]);
    }
}
