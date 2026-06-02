<?php

declare(strict_types=1);

namespace App\Categories\Infrastructure\Controller;

use App\Categories\Domain\Category;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/categories', name: 'categories_')]
class ListCategoriesController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $categories = $this->em->getRepository(Category::class)->findBy(
            ['deletedAt' => null],
            ['order' => 'ASC'],
        );

        return new JsonResponse(array_map(
            fn (Category $c) => [
                'id'     => $c->getId(),
                'name'   => $c->getName(),
                'image'  => $c->getImage(),
                'order'  => $c->getOrder(),
            ],
            $categories,
        ));
    }
}
