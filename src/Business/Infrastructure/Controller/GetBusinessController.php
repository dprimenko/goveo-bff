<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Controller;

use App\Business\Domain\BusinessRepository;
use App\Follows\Domain\FollowTarget;
use App\Follows\Infrastructure\Service\FollowerCounter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/businesses', name: 'pub_businesses_')]
class GetBusinessController
{
    public function __construct(
        private readonly BusinessRepository $repository,
        private readonly FollowerCounter $followers,
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
            // Recuento real de user_follows, salvo que meta.followers lo sobrescriba.
            'followers'   => $this->followers->resolve(
                FollowTarget::Business,
                $business->getId(),
                $business->getMeta(),
            ),
        ]);
    }
}
