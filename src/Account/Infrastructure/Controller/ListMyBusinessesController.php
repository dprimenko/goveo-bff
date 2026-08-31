<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Controller;

use App\Business\Domain\Business;
use App\Business\Domain\BusinessManagerRepository;
use App\Business\Domain\BusinessRepository;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Negocios que gestiona el usuario — la pantalla "Gestión de negocios".
 *
 * `/api/auth/me` ya devuelve los ids, pero para pintar la lista hacen falta
 * nombre, portada y si están validados; esto evita N llamadas al detalle.
 */
#[Route('/api/account/businesses', name: 'account_businesses', methods: ['GET'])]
class ListMyBusinessesController
{
    public function __construct(
        private readonly BusinessManagerRepository $managers,
        private readonly BusinessRepository $businesses,
        private readonly LocalUserResolver $currentUser,
    ) {}

    public function __invoke(): Response
    {
        $userId = $this->currentUser->currentId();

        if ($userId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $items = [];

        foreach ($this->managers->findByUserId($userId) as $manager) {
            $business = $this->businesses->findById($manager->getBusinessId());
            if ($business !== null) {
                $items[] = $this->serialize($business);
            }
        }

        return new JsonResponse(['items' => $items, 'total' => count($items)]);
    }

    private function serialize(Business $b): array
    {
        return [
            'id'         => $b->getId(),
            'name'       => $b->getName(),
            'avatar'     => $b->getAvatar(),
            'main_image' => $b->getMainImage(),
            // Sin `verified_at` la ficha está pendiente de validación.
            'verified'   => $b->getVerifiedAt() !== null,
        ];
    }
}
