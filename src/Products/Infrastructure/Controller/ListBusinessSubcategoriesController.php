<?php

declare(strict_types=1);

namespace App\Products\Infrastructure\Controller;

use App\Business\Domain\BusinessRepository;
use App\Products\Domain\ProductRepository;
use App\Products\Domain\ProductSubcategory;
use App\Products\Domain\ProductSubcategoryRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/businesses', name: 'pub_business_subcategories_')]
class ListBusinessSubcategoriesController
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly ProductSubcategoryRepository $subcategories,
        private readonly ProductRepository $products,
    ) {}

    #[Route('/{id}/subcategories', name: 'list', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        // Accept business id (guid) or slug, like GetBusinessController.
        $business = $this->businesses->findById($id)
            ?? $this->businesses->findBySlug($id);

        if ($business === null) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $subcategories = $this->subcategories->findByBusinessId($business->getId());

        // La ficha pública enseña **las que tienen algo dentro**.
        //
        // Antes daba igual: una subcategoría vacía sólo existía si alguien la
        // había creado a mano, y borrarla era cosa suya. La de promociones, en
        // cambio, la creamos nosotros en todos los negocios, así que sin este
        // filtro cada tienda estrenaría un chip «Promos» que al pulsarlo no
        // enseña nada — y la ficha diría que hay ofertas donde no las hay.
        $conProductos = $this->products->subcategoryIdsWithProducts($business->getId());

        return new JsonResponse(array_values(array_map(
            fn (ProductSubcategory $s) => [
                'id'         => $s->getId(),
                'name'       => $s->getName(),
                'sort_order' => $s->getSortOrder(),
                'kind'       => $s->getKind(),
            ],
            array_filter(
                $subcategories,
                static fn (ProductSubcategory $s) => !$s->isPromos()
                    || in_array($s->getId(), $conProductos, true),
            ),
        )));
    }
}
