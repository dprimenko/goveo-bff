<?php

declare(strict_types=1);

namespace App\Badges\Infrastructure\Controller;

use App\Badges\Domain\BadgeRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** GET /public/badges — el catálogo, para el filtro de la búsqueda y el panel. */
#[Route('/public/badges', name: 'pub_badges_list', methods: ['GET'])]
class ListBadgesController
{
    public function __construct(
        private readonly BadgeRepository $badges,
    ) {}

    public function __invoke(): Response
    {
        return new JsonResponse($this->badges->all());
    }
}
