<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Controller;

use App\Business\Domain\BusinessRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/businesses', name: 'pub_businesses_')]
class GetBusinessController
{
    public function __construct(
        private readonly BusinessRepository $repository,
    ) {}

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        $business = $this->repository->findById($id)
            ?? $this->repository->findBySlug($id);

        if ($business === null) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'id'          => $business->getId(),
            'name'        => $business->getName(),
            'description' => $business->getDescription(),
            'avatar'      => $business->getAvatar(),
            'main_image'  => $business->getMainImage(),
            'meta'        => $business->getMeta(),
        ]);
    }
}
