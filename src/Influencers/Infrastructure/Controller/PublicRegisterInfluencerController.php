<?php

declare(strict_types=1);

namespace App\Influencers\Infrastructure\Controller;

use App\Influencers\Application\InfluencerRegistration;
use App\Influencers\Domain\InfluencerProfileRules as Rules;
use App\Influencers\Domain\InfluencerRepository;
use App\Shared\Infrastructure\Storage\BunnyStorageService;
use App\Users\Domain\UserRepository;
use App\Users\Infrastructure\Service\LocalUserResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Alta de creador (`goveo.app/alta-creador`, también dentro de la app). Como la
 * de negocio (`/public/registration/business`): **pública**, la cuenta se crea
 * a partir del correo, y con sesión se cuelga de esa cuenta.
 *
 * JSON a secas, o multipart con la foto en `avatar` y lo demás en `payload`.
 *
 * Respuestas de error que la web distingue:
 *  - 409 `account_exists`: el correo ya tiene cuenta → que entre y reenvíe.
 *  - 409 `already_influencer`: esa cuenta ya es creador (uno por cuenta).
 *  - 409 `username_taken`.
 *  - 422 `validation_failed` con `fields`.
 */
#[Route('/public/registration/influencer', name: 'public_register_influencer', methods: ['POST'])]
final class PublicRegisterInfluencerController
{
    public function __construct(
        private readonly InfluencerRegistration $registration,
        private readonly InfluencerRepository $influencers,
        private readonly UserRepository $users,
        private readonly LocalUserResolver $currentUser,
        private readonly BunnyStorageService $storage,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): Response
    {
        $raw     = $request->request->get('payload');
        $payload = json_decode(is_string($raw) ? $raw : ($request->getContent() ?: '{}'), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        $data = [
            'email'    => strtolower(trim((string) ($payload['email'] ?? ''))),
            'name'     => trim((string) ($payload['name'] ?? '')),
            'username' => strtolower(ltrim(trim((string) ($payload['username'] ?? '')), '@')),
            'bio'      => Rules::bio($payload['bio'] ?? null),
            'socials'  => [],
        ];

        $errors = [];
        if (!Rules::validName($data['name'])) {
            $errors['name'] = 'required';
        }
        if (!Rules::validUsername($data['username'])) {
            $errors['username'] = 'invalid';
        }
        foreach (Rules::SOCIALS as $network) {
            $handle = Rules::socialHandle($payload[$network] ?? null);
            if ($handle === false) {
                $errors[$network] = 'invalid';
            } elseif ($handle !== null) {
                $data['socials'][$network] = $handle;
            }
        }

        // Con sesión, el correo lo fija el token y el del formulario no cuenta:
        // si no, bastaría con entrar como uno y reclamar el correo de otro.
        $owner  = null;
        $userId = $this->currentUser->currentId();
        if ($userId !== null) {
            $owner = $this->users->findById($userId);
        }
        if ($owner === null && !filter_var($data['email'], \FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'invalid';
        }

        if ($errors !== []) {
            return new JsonResponse(['error' => 'validation_failed', 'fields' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($owner === null) {
            $existing = $this->users->findByEmail($data['email']);
            if ($existing !== null) {
                // Ya tiene cuenta: que entre. Se comprueba al enviar y no antes,
                // para no dar un «¿existe este correo?» con el que probar
                // direcciones (lo mismo que el alta de negocio).
                return new JsonResponse(['error' => 'account_exists', 'email' => $existing->getEmail()], Response::HTTP_CONFLICT);
            }
        } elseif ($this->influencers->findByUserId($owner->getId()) !== null) {
            return new JsonResponse(['error' => 'already_influencer'], Response::HTTP_CONFLICT);
        }

        if ($this->influencers->findByUsername($data['username']) !== null) {
            return new JsonResponse(['error' => 'username_taken'], Response::HTTP_CONFLICT);
        }

        try {
            $result = $this->registration->register($data, $owner);
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo completar el alta de creador: {message}', [
                'message'   => $e->getMessage(),
                'exception' => $e,
            ]);

            return new JsonResponse(['error' => 'could_not_register'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $influencer = $result['influencer'];

        // La foto, después y sin tumbar el alta: se cambia luego desde la app.
        $file = $request->files->get('avatar');
        if ($file !== null && $file->isValid()) {
            try {
                $influencer->setAvatar($this->storage->uploadInfluencerAvatar(
                    $influencer->getId(),
                    (string) file_get_contents($file->getPathname()),
                ));
                $this->influencers->save($influencer);
            } catch (\Throwable $e) {
                $this->logger->warning('No se pudo subir la foto del alta de creador: {message}', [
                    'message'    => $e->getMessage(),
                    'influencer' => $influencer->getId(),
                ]);
            }
        }

        return new JsonResponse([
            'influencer' => [
                'id'       => $influencer->getId(),
                'username' => $influencer->getUsername(),
                'name'     => $influencer->getName(),
                'verified' => false,
            ],
            'account_created' => $result['account_created'],
        ], Response::HTTP_CREATED);
    }
}
