<?php

declare(strict_types=1);

namespace App\Influencers\Infrastructure\Controller;

use App\Influencers\Domain\Influencer;
use App\Influencers\Domain\InfluencerProfileRules as Rules;
use App\Influencers\Domain\InfluencerRepository;
use App\Shared\Infrastructure\Storage\BunnyStorageService;
use App\Shared\Infrastructure\Storage\StorageException;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * El perfil de creador de quien tiene la sesión, para editarlo desde la app
 * (Mi cuenta → Editar perfil de creador). Uno por cuenta.
 *
 *   GET   /api/account/influencer         → el perfil
 *   PATCH /api/account/influencer         → sólo lo que cambia
 *   POST  /api/account/influencer/avatar  → la foto (multipart, `file`)
 *
 * Editar no lo devuelve a validación: cambiar la bio o la foto no lo hace otro
 * creador, y obligar a esperar por eso sólo frenaría a quien lo cuida.
 */
#[Route('/api/account/influencer', name: 'account_influencer_')]
final class MyInfluencerController
{
    public function __construct(
        private readonly InfluencerRepository $influencers,
        private readonly LocalUserResolver $currentUser,
        private readonly BunnyStorageService $storage,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(): Response
    {
        $influencer = $this->mine();

        return $influencer instanceof Influencer ? new JsonResponse(self::toJson($influencer)) : $influencer;
    }

    #[Route('', name: 'update', methods: ['PATCH'])]
    public function update(Request $request): Response
    {
        $influencer = $this->mine();
        if (!$influencer instanceof Influencer) {
            return $influencer;
        }

        $data = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        $errors = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if (Rules::validName($name)) {
                $influencer->setName($name);
            } else {
                $errors['name'] = 'required';
            }
        }

        if (array_key_exists('username', $data)) {
            $username = strtolower(ltrim(trim((string) $data['username']), '@'));
            // Sin cambios no se valida: los heredados pueden no cumplir el
            // formato de ahora.
            if ($username !== $influencer->getUsername()) {
                if (!Rules::validUsername($username)) {
                    $errors['username'] = 'invalid';
                } elseif ($this->influencers->findByUsername($username) !== null) {
                    return new JsonResponse(['error' => 'username_taken'], Response::HTTP_CONFLICT);
                } else {
                    $influencer->setUsername($username);
                }
            }
        }

        if (array_key_exists('bio', $data)) {
            $influencer->setBio(Rules::bio($data['bio']));
        }

        $meta = $influencer->getMeta() ?? [];
        foreach (Rules::SOCIALS as $network) {
            if (!array_key_exists($network, $data)) {
                continue;
            }
            $handle = Rules::socialHandle($data[$network]);
            if ($handle === false) {
                $errors[$network] = 'invalid';
            } elseif ($handle === null) {
                unset($meta[$network]);
            } else {
                $meta[$network] = $handle;
            }
        }

        if ($errors !== []) {
            return new JsonResponse(['error' => 'validation_failed', 'fields' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $influencer->setMeta($meta === [] ? null : $meta);
        $this->influencers->save($influencer);

        return new JsonResponse(self::toJson($influencer));
    }

    #[Route('/avatar', name: 'avatar', methods: ['POST'])]
    public function avatar(Request $request): Response
    {
        $influencer = $this->mine();
        if (!$influencer instanceof Influencer) {
            return $influencer;
        }

        $file = $request->files->get('file');
        if ($file === null || !$file->isValid()) {
            return new JsonResponse(['error' => 'file_required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $url = $this->storage->uploadInfluencerAvatar($influencer->getId(), (string) file_get_contents($file->getPathname()));
        } catch (StorageException $e) {
            return new JsonResponse(['error' => 'invalid_image'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('Fallo subiendo la foto de un creador: {message}', [
                'message'    => $e->getMessage(),
                'influencer' => $influencer->getId(),
            ]);

            return new JsonResponse(['error' => 'upload_failed'], Response::HTTP_BAD_GATEWAY);
        }

        $previous = $influencer->getAvatar();
        $influencer->setAvatar($url);
        $this->influencers->save($influencer);
        // Después de guardar: al revés, un fallo dejaría el perfil apuntando a
        // una imagen ya borrada.
        $this->storage->deleteByUrl($previous);

        return new JsonResponse(['url' => $url], Response::HTTP_CREATED);
    }

    private function mine(): Influencer|JsonResponse
    {
        $userId = $this->currentUser->currentId();
        if ($userId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $influencer = $this->influencers->findByUserId($userId);
        if ($influencer === null || $influencer->isDeleted()) {
            return new JsonResponse(['error' => 'not_influencer'], Response::HTTP_NOT_FOUND);
        }

        return $influencer;
    }

    /** @return array<string,mixed> */
    private static function toJson(Influencer $influencer): array
    {
        $meta = $influencer->getMeta() ?? [];

        return [
            'id'        => $influencer->getId(),
            'name'      => $influencer->getName(),
            'username'  => $influencer->getUsername(),
            'avatar'    => $influencer->getAvatar(),
            'bio'       => $influencer->getBio(),
            'instagram' => $meta['instagram'] ?? null,
            'tiktok'    => $meta['tiktok'] ?? null,
            'verified'  => $influencer->isVerified(),
        ];
    }
}
