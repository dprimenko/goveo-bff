<?php

declare(strict_types=1);

namespace App\Influencers\Infrastructure\Controller;

use App\Follows\Domain\FollowTarget;
use App\Follows\Infrastructure\Service\FollowerCounter;
use App\Influencers\Domain\InfluencerRepository;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/influencers', name: 'pub_influencers_')]
class GetInfluencerController
{
    public function __construct(
        private readonly InfluencerRepository $repository,
        private readonly FollowerCounter $followers,
        private readonly LocalUserResolver $currentUser,
    ) {}

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        $influencer = $this->repository->findById($id)
            ?? $this->repository->findByUsername($id);

        // Uno archivado desde el panel no tiene perfil: sus vídeos ya no salen, y
        // su ficha no debería seguir respondiendo por un enlace compartido.
        if ($influencer === null || $influencer->isDeleted()) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        // Sin validar, sólo lo ve él: puede abrir su perfil mientras espera,
        // pero no se comparte ni se encuentra hasta que el equipo lo aprueba.
        $isOwner = $this->currentUser->currentId() === $influencer->getUserId();
        if (!$influencer->isVerified() && !$isOwner) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $meta = $influencer->getMeta() ?? [];

        return new JsonResponse([
            'id'        => $influencer->getId(),
            'name'      => $influencer->getName(),
            'avatar'    => $influencer->getAvatar(),
            'bio'       => $influencer->getBio(),
            'username'  => $influencer->getUsername(),
            // Sus redes, si las dio en el alta: `instagram`, `tiktok`.
            // Objeto aunque esté vacío: `[]` en JSON sería una lista.
            'socials'   => (object) array_filter([
                'instagram' => $meta['instagram'] ?? null,
                'tiktok'    => $meta['tiktok'] ?? null,
            ]),
            'verified'  => $influencer->isVerified(),
            // Recuento real de user_follows, salvo que meta.followers lo sobrescriba.
            'followers' => $this->followers->resolve(
                FollowTarget::Influencer,
                $influencer->getId(),
                $influencer->getMeta(),
            ),
        ]);
    }
}
