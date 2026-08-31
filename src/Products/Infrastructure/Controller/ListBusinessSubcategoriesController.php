<?php

declare(strict_types=1);

namespace App\Products\Infrastructure\Controller;

use App\Business\Domain\BusinessRepository;
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

        return new JsonResponse(array_map(
            fn (ProductSubcategory $s) => [
                'id'         => $s->getId(),
                'name'       => $s->getName(),
                'sort_order' => $s->getSortOrder(),
            ],
            $subcategories,
        ));
    }
}
