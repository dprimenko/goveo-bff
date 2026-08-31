<?php

declare(strict_types=1);

namespace App\Influencers\Infrastructure\Controller;

use App\Influencers\Domain\Influencer;
use App\Influencers\Domain\InfluencerRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /public/influencers?q=&page=&size=
 *
 * Listado/búsqueda de creadores por nombre o username (sin tildes ni
 * mayúsculas). Sin `q` devuelve todos por orden alfabético.
 */
#[Route('/public/influencers', name: 'pub_influencers_list', methods: ['GET'])]
class ListInfluencersController
{
    private const DEFAULT_SIZE = 20;
    private const MAX_SIZE     = 50;

    public function __construct(
        private readonly InfluencerRepository $repository,
    ) {}

    public function __invoke(Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $page  = max(1, (int) $request->query->get('page', 1));
        $size  = min(
            self::MAX_SIZE,
            max(1, (int) $request->query->get('size', self::DEFAULT_SIZE)),
        );

        $result = $this->repository->searchByName(
            $query !== '' ? $query : null,
            $page,
            $size,
        );

        return new JsonResponse([
            'items' => array_map($this->serialize(...), $result['items']),
            'total' => $result['total'],
            'page'  => $page,
            'size'  => $size,
        ]);
    }

    private function serialize(Influencer $i): array
    {
        return [
            'id'       => $i->getId(),
            'name'     => $i->getName(),
            'username' => $i->getUsername(),
            'avatar'   => $i->getAvatar(),
        ];
    }
}
