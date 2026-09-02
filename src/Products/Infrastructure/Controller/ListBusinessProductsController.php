<?php

declare(strict_types=1);

namespace App\Products\Infrastructure\Controller;

use App\Business\Domain\BusinessRepository;
use App\Products\Domain\Product;
use App\Products\Domain\ProductRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/businesses', name: 'pub_business_products_')]
class ListBusinessProductsController
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly ProductRepository $products,
    ) {}

    #[Route('/{id}/products', name: 'list', methods: ['GET'])]
    public function __invoke(string $id, Request $request): Response
    {
        // Accept business id (guid) or slug, like GetBusinessController.
        $business = $this->businesses->findById($id)
            ?? $this->businesses->findBySlug($id);

        if ($business === null) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        // ?subcategory=<guid> → filter by business subcategory
        $subcategory = $request->query->getString('subcategory', '');
        $subcategory = $subcategory !== '' ? $subcategory : null;

        // 0-based pagination (matches /public/geostories)
        $page = max(0, $request->query->getInt('page', 0));
        $size = min(50, max(1, $request->query->getInt('size', 20)));

        $result = $this->products->findByBusinessPaginated(
            $business->getId(),
            $subcategory,
            $page,
            $size,
        );

        return new JsonResponse([
            'items' => array_map(
                fn (Product $p) => [
                    'id'              => $p->getId(),
                    'title'           => $p->getTitle(),
                    'description'     => $p->getDescription(),
                    // `image` se conserva porque lo consume la app de Flutter
                    // que sigue publicada; `images` es la lista completa, que es
                    // lo que necesita la ficha para el carrusel.
                    'image'              => $this->primaryImage($p),
                    'images'             => $p->imageUrls(),
                    'description_format' => $p->getDescriptionFormat()->value,
                    'subcategory_id'  => $p->getSubcategoryId(),
                    'category_id'     => $p->getCategoryId(),
                    'price_amount'    => $p->getPriceAmount(),
                    'price_currency'  => $p->getPriceCurrency(),
                    'formatted_price' => $p->getFormattedPrice(),
                ],
                $result['items'],
            ),
            'total' => $result['total'],
            'page'  => $page,
        ]);
    }

    /** First image url by `order`, or null. */
    private function primaryImage(Product $product): ?string
    {
        $images = $product->getImages();
        if (empty($images)) {
            return null;
        }

        usort(
            $images,
            static fn (array $a, array $b): int => ($a['order'] ?? 0) <=> ($b['order'] ?? 0),
        );

        return $images[0]['url'] ?? null;
    }
}
