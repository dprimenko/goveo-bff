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
 * GET /api/admin/businesses?status=pending|rejected|verified|removed|all
 *                          &q=&city=&category=&plan=&sort=&dir=&page=&size=
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
 *
 * **Filtrar y ordenar se hacen aquí y no en el navegador**, aunque la tabla
 * quepa en pantalla: lo que se ve es una página de veinte de cuatrocientas y
 * pico, así que ordenar lo visible ordena veinte filas cualesquiera y contesta a
 * otra pregunta. «Los que más vídeos tienen» sólo significa algo sobre el total.
 */
#[Route('/api/admin/businesses', name: 'admin_businesses_list', methods: ['GET'])]
#[IsGranted('ROLE_BUSINESS_VERIFY')]
class ListBusinessReviewsController
{
    private const DEFAULT_SIZE = 20;
    private const MAX_SIZE     = 100;

    /**
     * Por qué se puede ordenar, y con qué expresión SQL.
     *
     * La lista es blanca a propósito: el valor llega por query y esto acaba
     * dentro de un `ORDER BY`, donde no hay parámetros que valgan.
     *
     * Los nulos van siempre al final, ordene como ordene: un negocio sin
     * categoría no es «el primero por categoría», es uno que no la tiene, y
     * verlos ocupando la primera página en las dos direcciones esconde justo lo
     * que se estaba buscando.
     */
    private const SORTS = [
        'created'  => 'b.created_at',
        'name'     => 'b.name',
        'city'     => 'b.city',
        'category' => 'c.name',
        // Por lo que cuesta y no por el nombre: FREE, PREMIUM, PLATINUM y TOP 3
        // tienen un orden —de menos a más— que su nombre no respeta. Alfabético
        // pondría PLATINUM antes que PREMIUM y TOP 3 en medio.
        //
        // Y por el precio **de la tarifa**, no por lo que se cobró: sólo 4 de
        // las 17 suscripciones tienen `amount_cents`, así que ordenar por él
        // dejaba las otras trece a nulo y la columna salía sin ordenar. Lo que
        // se enseña sigue siendo lo cobrado; lo que ordena es a qué tarifa
        // pertenece.
        'plan'     => 'plan_rank',
        'products' => 'product_count',
        'videos'   => 'video_count',
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

        $city     = trim((string) $request->query->get('city', ''));
        $category = trim((string) $request->query->get('category', ''));
        $plan     = trim((string) $request->query->get('plan', ''));

        // Los tres primeros son estados de un negocio vivo; `removed` corta por
        // otro sitio, así que la condición de «no borrado» se añade sólo a ésos.
        $condition = match ($status) {
            'rejected' => 'b.deleted_at IS NULL AND b.rejected_at IS NOT NULL',
            'verified' => 'b.deleted_at IS NULL AND b.verified_at IS NOT NULL',
            // **Lo que no está cobrado no se revisa.** Un alta web crea la ficha
            // antes de pagar, así que sin esta condición la cola se llenaba de
            // negocios que quizá abandonaron en la pasarela, y validarlos
            // significaba publicar a alguien que no ha pagado.
            //
            // Se excluye sólo lo que tiene un cobro **pendiente y ninguno
            // resuelto**: los importados no tienen suscripción y siguen
            // apareciendo, y las tarifas gratuitas nacen activas.
            'pending'  => 'b.deleted_at IS NULL AND b.verified_at IS NULL AND b.rejected_at IS NULL
                           AND NOT EXISTS (
                               SELECT 1 FROM business_subscriptions pend
                                WHERE pend.business_id = b.id
                                  AND pend.status = \'pending_payment\'
                                  AND NOT EXISTS (
                                      SELECT 1 FROM business_subscriptions ok
                                       WHERE ok.business_id = b.id
                                         AND ok.status <> \'pending_payment\'
                                  )
                           )',
            'removed'  => 'b.deleted_at IS NOT NULL',
            // Cualquiera que no esté borrado: lo usa el selector de dueño de un
            // vídeo, donde da igual si el negocio ya está validado.
            'all'      => 'b.deleted_at IS NULL',
            default    => null,
        };

        if ($condition === null) {
            return new JsonResponse(
                ['error' => 'Unknown status. Use pending, rejected, verified, removed or all.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $where  = $condition;
        $params = [];

        if ($q !== '') {
            // `unaccent`, como en la búsqueda pública: quien escribe «jamoneria»
            // espera encontrar «Jamonería».
            $where   .= ' AND unaccent(lower(b.name)) LIKE unaccent(lower(?))';
            $params[] = '%' . $q . '%';
        }

        if ($city !== '') {
            // Comparación exacta: el valor no lo teclea nadie, sale del
            // desplegable, que se construye con estos mismos textos.
            // `none` es el hueco que deja la geocodificación —los que no tienen
            // coordenadas, o los que entraron después del último relleno—, y sin
            // esa opción no habría forma de encontrarlos.
            if ($city === 'none') {
                $where .= ' AND b.city IS NULL';
            } else {
                $where   .= ' AND b.city = ?';
                $params[] = $city;
            }
        }

        if ($category !== '') {
            // Por slug o por id, como el filtro público: el panel manda el slug,
            // que es lo que se lee en la URL.
            $where   .= ' AND (c.slug = ? OR c.id::text = ?)';
            $params[] = $category;
            $params[] = $category;
        }

        if ($plan !== '') {
            // `none` son los que no tienen ninguna suscripción, que hoy son casi
            // todos: los importados nunca pasaron por facturación. Es la única
            // forma de encontrarlos hasta que se lance
            // `goveo:billing:assign-free-plan`, y después, de ver si queda algo
            // suelto.
            $where .= $plan === 'none'
                ? ' AND NOT EXISTS (SELECT 1 FROM business_subscriptions s WHERE s.business_id = b.id)'
                : ' AND EXISTS (SELECT 1 FROM business_subscriptions s
                                  JOIN billing_plans p ON p.id = s.billing_plan_id
                                 WHERE s.business_id = b.id AND p.code = ?)';

            if ($plan !== 'none') {
                $params[] = $plan;
            }
        }

        // El `LEFT JOIN` de categorías hace falta ya en el recuento: el filtro
        // por categoría se apoya en él.
        $from = 'FROM business b LEFT JOIN categories c ON c.id = b.category_id';

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) {$from} WHERE {$where}", $params);

        $rows = $this->db->fetchAllAssociative(
            "SELECT b.id, b.slug, b.name, b.avatar, b.main_image, b.meta, b.city,
                    b.created_at, b.verified_at, b.rejected_at, b.deleted_at,
                    c.slug AS category_slug, c.name AS category_name,
                    ST_Y(b.location::geometry) AS lat,
                    ST_X(b.location::geometry) AS lng,
                    -- Lo que tiene colgado, que es lo que dice si una ficha está
                    -- viva o es un cascarón validado hace un año. Se cuenta lo
                    -- mismo que en la ficha (`GetBusinessReviewController`): todo
                    -- lo no borrado, borradores incluidos — quien revisa quiere
                    -- saber qué hay ahí dentro, no qué se publica.
                    (SELECT count(*) FROM products pr
                      WHERE pr.business_id = b.id AND pr.deleted_at IS NULL) AS product_count,
                    (SELECT count(*) FROM geostories g
                      WHERE g.business_id = b.id AND g.deleted_at IS NULL)   AS video_count,
                    -- La tarifa contratada. Se coge la última suscripción y no la
                    -- «activa»: una en impago o pendiente de pago también dice
                    -- con qué se dio de alta, y es justo lo que hay que mirar.
                    (SELECT p.name FROM business_subscriptions s
                       LEFT JOIN billing_plans p ON p.id = s.billing_plan_id
                      WHERE s.business_id = b.id
                      ORDER BY s.created_at DESC LIMIT 1) AS plan_name,
                    (SELECT p.code FROM business_subscriptions s
                       LEFT JOIN billing_plans p ON p.id = s.billing_plan_id
                      WHERE s.business_id = b.id
                      ORDER BY s.created_at DESC LIMIT 1) AS plan_code,
                    (SELECT s.amount_cents FROM business_subscriptions s
                      WHERE s.business_id = b.id
                      ORDER BY s.created_at DESC LIMIT 1) AS plan_amount,
                    -- Sólo para ordenar: el precio de catálogo de su tarifa.
                    (SELECT p.amount_cents FROM business_subscriptions s
                       JOIN billing_plans p ON p.id = s.billing_plan_id
                      WHERE s.business_id = b.id
                      ORDER BY s.created_at DESC LIMIT 1) AS plan_rank,
                    (SELECT s.status FROM business_subscriptions s
                      WHERE s.business_id = b.id
                      ORDER BY s.created_at DESC LIMIT 1) AS plan_status
               {$from}
              WHERE {$where}
              ORDER BY " . $this->order($request, $status) . "
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

    /**
     * El `ORDER BY`, que por defecto depende de la pestaña.
     *
     * La cola se lee por antigüedad —quien lleva más tiempo esperando es a quien
     * peor se le está atendiendo—, pero lo ya decidido se consulta al revés: lo
     * que se busca ahí es «qué hemos hecho últimamente», y la fecha de alta no
     * ordena nada porque puede ser de hace dos años.
     *
     * Un `sort` desconocido se ignora en vez de responder un error: el valor
     * viaja en la URL del panel y un enlace guardado de cuando la columna se
     * llamaba de otra forma tiene que seguir abriendo la lista.
     */
    private function order(Request $request, string $status): string
    {
        $sort = (string) $request->query->get('sort', '');

        if (!isset(self::SORTS[$sort])) {
            return match ($status) {
                'verified' => 'b.verified_at DESC',
                'rejected' => 'b.rejected_at DESC',
                'removed'  => 'b.deleted_at DESC',
                default    => 'b.created_at ASC',
            };
        }

        $direction = strtolower((string) $request->query->get('dir', 'asc')) === 'desc' ? 'DESC' : 'ASC';

        // El id de desempate deja el orden estable: sin él, dos negocios con la
        // misma categoría pueden salir en otro sitio al pasar de página y
        // aparecer dos veces o ninguna.
        return sprintf('%s %s NULLS LAST, b.id ASC', self::SORTS[$sort], $direction);
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
            // La ciudad no sale de ese texto: la pone la geocodificación inversa
            // sobre las coordenadas. Nula es «aún no se ha mirado».
            'city'       => $row['city'],
            // Para poder abrir la dirección en el mapa sin fiarse del texto:
            // el mismo texto puede geocodificar en otro sitio.
            'location'   => $row['lat'] === null ? null : [
                'lat' => (float) $row['lat'],
                'lng' => (float) $row['lng'],
            ],
            'plan'       => $row['plan_name'] === null ? null : [
                'name'         => $row['plan_name'],
                'code'         => $row['plan_code'],
                'amount_cents' => $row['plan_amount'] === null ? null : (int) $row['plan_amount'],
                'status'       => $row['plan_status'],
            ],
            // Lo que tiene publicado, para no tener que abrir la ficha.
            'counts'     => [
                'products' => (int) $row['product_count'],
                'videos'   => (int) $row['video_count'],
            ],
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
            'deleted_at'  => self::iso($row['deleted_at']),
        ];
    }
}
