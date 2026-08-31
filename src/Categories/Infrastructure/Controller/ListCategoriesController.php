<?php

declare(strict_types=1);

namespace App\Categories\Infrastructure\Controller;

use App\Categories\Domain\Category;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/categories', name: 'pub_categories_')]
class ListCategoriesController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        // ?partner=xxx → filter by partner slug; no param → partner IS NULL
        $partner = $request->query->has('partner') ? $request->query->get('partner') : null;

        // ?types=uuid1,uuid2 → filter by category type IDs (categories_category_types pivot)
        $typesRaw = $request->query->getString('types', '');
        $typeIds  = $typesRaw !== '' ? array_values(array_filter(explode(',', $typesRaw))) : [];

        if (!empty($typeIds)) {
            $conn         = $this->em->getConnection();
            $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
            $partnerWhere = $partner === null ? 'c.partner IS NULL' : 'c.partner = ?';
            $params       = $partner === null ? $typeIds : array_merge([$partner], $typeIds);

            $ids = $conn->fetchFirstColumn("
                SELECT DISTINCT c.id, c.order
                FROM categories c
                INNER JOIN categories_category_types cct ON cct.category_id = c.id
                WHERE c.deleted_at IS NULL
                  AND {$partnerWhere}
                  AND cct.type_id IN ({$placeholders})
                ORDER BY c.order ASC
            ", $params);

            $categories = array_values(array_filter(
                array_map(fn (string $id) => $this->em->find(Category::class, $id), $ids)
            ));
        } else {
            $categories = $this->em->getRepository(Category::class)->findBy(
                ['deletedAt' => null, 'partner' => $partner],
                ['order' => 'ASC'],
            );
        }

        // ?mode=influencer|business → include that mode + 'both'
        $mode = $request->query->get('mode');
        if ($mode !== null && $mode !== '') {
            $categories = array_values(array_filter(
                $categories,
                static fn (Category $c) => in_array($c->getMode(), [$mode, Category::MODE_BOTH], true),
            ));
        }

        return new JsonResponse(array_map(
            fn (Category $c) => [
                'id'    => $c->getId(),
                'slug'  => $c->getSlug(),
                'name'  => $c->getName(),
                'image' => $c->getImage(),
                'order' => $c->getOrder(),
                'mode'  => $c->getMode(),
            ],
            $categories,
        ));
    }
}
