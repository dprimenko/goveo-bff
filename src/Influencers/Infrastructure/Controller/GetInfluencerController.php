<?php

declare(strict_types=1);

namespace App\Influencers\Infrastructure\Controller;

use App\Influencers\Domain\InfluencerRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/influencers', name: 'pub_influencers_')]
class GetInfluencerController
{
    public function __construct(
        private readonly InfluencerRepository $repository,
    ) {}

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        $influencer = $this->repository->findById($id)
            ?? $this->repository->findByUsername($id);

        if ($influencer === null) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $meta = $influencer->getMeta() ?? [];

        return new JsonResponse([
            'id'        => $influencer->getId(),
            'name'      => $influencer->getName(),
            'avatar'    => $influencer->getAvatar(),
            'bio'       => $influencer->getBio(),
            'followers' => $meta['followers'] ?? null,
        ]);
    }
}
