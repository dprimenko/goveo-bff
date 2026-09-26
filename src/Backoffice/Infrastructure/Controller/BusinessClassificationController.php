<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use App\Badges\Domain\BadgeRepository;
use App\Business\Application\BusinessCategory;
use App\Business\Domain\BusinessRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Clasificar negocios desde el panel: subcategoría y badges.
 *
 * - `PATCH /api/admin/businesses/{id}/classification` `{category_id?, badges?}`.
 *   **No retira la validación**, a diferencia del `PATCH` del gestor: aquí la
 *   cambia Goveo, que es quien valida. Si la retirara, sacar cada bar de
 *   «Restaurantes» lo quitaría del mapa hasta volver a validarlo. `badges` es
 *   la lista completa (slugs o ids): lo que no va, se quita.
 * - `GET /api/admin/businesses/classification.csv?category=&unclassified=1`:
 *   para repasarlos en una hoja —el reparto de Hostelería, los que quedaron
 *   colgados del grupo— con la web a mano para mirar qué es cada uno.
 */
#[Route('/api/admin/businesses', name: 'admin_business_classification_')]
#[IsGranted('ROLE_BUSINESS_EDIT')]
class BusinessClassificationController
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly BusinessCategory $category,
        private readonly BadgeRepository $badges,
        private readonly Connection $db,
    ) {}

    #[Route('/{id}/classification', name: 'update', methods: ['PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function update(string $id, Request $request): Response
    {
        $business = $this->businesses->findById($id);
        if ($business === null) {
            return new JsonResponse(['error' => 'Business not found.'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('category_id', $payload)) {
            $categoryId = (string) $payload['category_id'];
            if (!$this->category->isAssignable($categoryId)) {
                return new JsonResponse(
                    ['error' => 'validation_failed', 'fields' => ['category_id' => 'invalid']],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            if ($business->reclassify($categoryId)) {
                $this->businesses->save($business);
                $this->category->syncProducts($business);
            }
        }

        $badges = null;
        if (array_key_exists('badges', $payload)) {
            if (!is_array($payload['badges'])) {
                return new JsonResponse(
                    ['error' => 'validation_failed', 'fields' => ['badges' => 'invalid']],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            $badges = $this->badges->replaceForBusiness($business->getId(), $payload['badges']);
        }

        return new JsonResponse([
            'id'          => $business->getId(),
            'category_id' => $business->getCategoryId(),
            'badges'      => $badges ?? ($this->badges->forBusinesses([$business->getId()])[$business->getId()] ?? []),
        ]);
    }

    #[Route('/classification.csv', name: 'export', methods: ['GET'], priority: 10)]
    public function export(Request $request): Response
    {
        $where  = ['b.deleted_at IS NULL'];
        $params = [];

        $category = trim((string) $request->query->get('category', ''));
        if ($category !== '') {
            $where[] = '(c.slug = ? OR c.id::text = ? OR cg.slug = ? OR cg.id::text = ?)';
            array_push($params, $category, $category, $category, $category);
        }
        if ($request->query->getBoolean('unclassified')) {
            $where[] = 'c.section IS NOT NULL';
        }

        $rows = $this->db->fetchAllAssociative(
            "SELECT b.id, b.name, b.city, b.meta->>'address' AS address, b.meta->>'website_url' AS website,
                    cg.slug AS group_slug, c.slug AS category_slug,
                    (SELECT string_agg(bd.slug, ' ' ORDER BY bd.\"order\")
                       FROM business_badges bb JOIN badges bd ON bd.id = bb.badge_id
                      WHERE bb.business_id = b.id) AS badges,
                    b.verified_at IS NOT NULL AS published
               FROM business b
               LEFT JOIN categories c  ON c.id = b.category_id
               LEFT JOIN categories cg ON cg.id = c.parent_id
              WHERE " . implode(' AND ', $where) . '
              ORDER BY cg."order" NULLS FIRST, c."order", b.name',
            $params,
        );

        $response = new StreamedResponse(static function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            // BOM: sin él, Excel abre las tildes como «JamonerÃ­a».
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'nombre', 'ciudad', 'direccion', 'web', 'grupo', 'categoria', 'badges', 'publicado'], ',', '"', '');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['id'], $r['name'], $r['city'], $r['address'], $r['website'],
                    // Colgado del grupo: la categoría es el grupo y la
                    // subcategoría está por poner.
                    $r['group_slug'] ?? $r['category_slug'],
                    $r['group_slug'] === null ? '' : $r['category_slug'],
                    $r['badges'] ?? '', $r['published'] ? 'si' : 'no',
                ], ',', '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="clasificacion.csv"');

        return $response;
    }
}
