<?php

declare(strict_types=1);

namespace App\Categories\Infrastructure\Controller;

use App\Categories\Domain\Category;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/categories', name: 'pub_categories_')]
class ListCategoriesController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function __invoke(): Response
    {
        $categories = $this->em->getRepository(Category::class)->findBy(
            ['deletedAt' => null],
            ['order' => 'ASC'],
        );

        return new JsonResponse(array_map(
            fn (Category $c) => [
                'id'    => $c->getId(),
                'slug'  => $c->getSlug(),
                'name'  => $c->getName(),
                'image' => $c->getImage(),
                'order' => $c->getOrder(),
            ],
            $categories,
        ));
    }
}
