<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /api/admin/businesses/{id}
 *
 * La ficha con la que se decide. El listado enseña lo justo para reconocer un
 * negocio; validarlo es otra cosa: hay que poder ver si existe de verdad, si la
 * ficha está presentable y si hay alguien detrás.
 *
 * De ahí lo que se devuelve y que no está en la lista:
 *
 * - **Portada, avatar y descripción**, que es lo que va a ver quien abra la app.
 * - **Dónde cae en el mapa**: una dirección se escribe a mano y puede geocodificar
 *   en otro país sin que el texto lo delate.
 * - **Quién lo creó** y cuántas personas lo gestionan.
 * - **La suscripción**: si hay uno cobrando, hay alguien detrás. Es la señal más
 *   fuerte de que el alta va en serio.
 * - **Qué ha subido**: una ficha con productos y vídeos está trabajada; una vacía
 *   puede ser una prueba de alguien que se dio de alta y se fue.
 */
#[Route('/api/admin/businesses/{id}', name: 'admin_business_get', methods: ['GET'])]
#[IsGranted('ROLE_BUSINESS_VERIFY')]
class GetBusinessReviewController
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function __invoke(string $id): Response
    {
        $row = $this->db->fetchAssociative(
            "SELECT b.id, b.slug, b.name, b.description, b.avatar, b.main_image, b.meta,
                    b.created_at, b.updated_at, b.verified_at, b.rejected_at, b.deleted_at,
                    ST_Y(b.location::geometry) AS lat,
                    ST_X(b.location::geometry) AS lng,
                    c.slug AS category_slug, c.name AS category_name,
                    u.email AS creator_email,
                    p.name AS partner_name,
                    (SELECT count(*) FROM products pr
                      WHERE pr.business_id = b.id AND pr.deleted_at IS NULL)   AS product_count,
                    (SELECT count(*) FROM geostories g
                      WHERE g.business_id = b.id AND g.deleted_at IS NULL)     AS video_count,
                    (SELECT count(*) FROM business_managers m
                      WHERE m.business_id = b.id AND m.deleted_at IS NULL)     AS manager_count
               FROM business b
          LEFT JOIN categories c ON c.id = b.category_id
          LEFT JOIN users u      ON u.id = b.creator_id
          LEFT JOIN partners p   ON p.id = b.partner_id
              WHERE b.id = ?",
            [$id],
        );

        if ($row === false) {
            return new JsonResponse(['error' => 'Business not found.'], Response::HTTP_NOT_FOUND);
        }

        $meta    = json_decode((string) ($row['meta'] ?? ''), true) ?: [];
        $billing = $meta['billing'] ?? [];

        return new JsonResponse([
            'id'          => $row['id'],
            'slug'        => $row['slug'],
            'name'        => $row['name'],
            'description' => $row['description'],
            'avatar'      => $row['avatar'],
            'main_image'  => $row['main_image'],
            'category'    => $row['category_slug'] === null ? null : [
                'slug' => $row['category_slug'],
                'name' => $row['category_name'],
            ],
            'address'  => $meta['address'] ?? null,
            // Puede no tener: la ficha se puede guardar sin dirección que
            // geocodifique, y entonces el negocio no sale ni en mapa ni en feed
            // aunque se valide. Verlo aquí evita validar algo que no se verá.
            'location' => $row['lat'] === null ? null : [
                'lat' => (float) $row['lat'],
                'lng' => (float) $row['lng'],
            ],
            'phone'       => $meta['public_phone'] ?? null,
            'is_whatsapp' => (bool) ($meta['is_whatsapp'] ?? false),
            'website'     => $meta['website_url'] ?? null,
            'booking_url' => $meta['booking_url'] ?? null,
            'billing'     => [
                'company_name' => $billing['company_name'] ?? null,
                'tax_id'       => $billing['tax_id'] ?? null,
                'email'        => $billing['email'] ?? null,
                'phone'        => $billing['phone'] ?? null,
                'address'      => $billing['address'] ?? null,
            ],
            'creator'      => ['email' => $row['creator_email']],
            'partner'      => $row['partner_name'],
            'subscription' => $this->subscription($id),
            'counts'       => [
                'products' => (int) $row['product_count'],
                'videos'   => (int) $row['video_count'],
                'managers' => (int) $row['manager_count'],
            ],
            'created_at'  => self::iso($row['created_at']),
            'updated_at'  => self::iso($row['updated_at']),
            'verified_at' => self::iso($row['verified_at']),
            'rejected_at' => self::iso($row['rejected_at']),
            'deleted_at'  => self::iso($row['deleted_at']),
        ]);
    }

    /**
     * La suscripción viva, si la hay. Se coge la más reciente porque un negocio
     * que se dio de baja y volvió tiene varias, y la que importa es la última.
     *
     * @return array<string, mixed>|null
     */
    private function subscription(string $businessId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT s.status, s.amount_cents, s.current_period_end, s.cancelled_at, p.name AS plan
               FROM business_subscriptions s
          LEFT JOIN billing_plans p ON p.id = s.billing_plan_id
              WHERE s.business_id = ?
           ORDER BY s.created_at DESC
              LIMIT 1',
            [$businessId],
        );

        if ($row === false) {
            return null;
        }

        return [
            'plan'         => $row['plan'],
            'status'       => $row['status'],
            'amount_cents' => $row['amount_cents'] === null ? null : (int) $row['amount_cents'],
            'period_end'   => self::iso($row['current_period_end']),
            'cancelled_at' => self::iso($row['cancelled_at']),
        ];
    }

    private static function iso(?string $timestamp): ?string
    {
        return $timestamp === null ? null : (new \DateTimeImmutable($timestamp))->format(\DATE_ATOM);
    }
}
