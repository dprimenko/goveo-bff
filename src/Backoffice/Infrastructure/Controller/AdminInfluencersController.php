<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use App\Account\Application\AccountProvisioner;
use App\Backoffice\Application\ReviewDecisionMailer;
use App\Influencers\Domain\InfluencerProfileRules;
use App\Influencers\Application\InfluencerArchiver;
use App\Influencers\Application\InfluencerPurger;
use App\Influencers\Domain\Influencer;
use App\Influencers\Domain\InfluencerRepository;
use App\Shared\Infrastructure\Storage\BunnyStorageService;
use App\Shared\Infrastructure\Storage\StorageException;
use App\Users\Domain\User;
use App\Users\Domain\UserRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Los influencers desde el panel: alta, edición, archivo y borrado.
 *
 * `GET    /api/admin/influencers?status=active|pending|removed&q=&page=&size=`
 * `GET    /api/admin/influencers/{id}`
 * `POST   /api/admin/influencers`            {name, username, bio?, email?}
 * `PATCH  /api/admin/influencers/{id}`       {name?, username?, bio?}
 * `POST   /api/admin/influencers/{id}/avatar` multipart `file`
 * `PUT    /api/admin/influencers/{id}/{remove|restore}`
 * `PUT    /api/admin/influencers/{id}/approve` (los del alta pública; avisa por correo)
 * `DELETE /api/admin/influencers/{id}`       (definitivo, sólo si ya está archivado)
 *
 * **Con los permisos de negocio** (`business.edit` y `business.delete`) y no con
 * unos propios: un influencer es otro publicador, como una tienda, y quien
 * gestiona unas gestiona los otros. Separarlos pediría roles nuevos en Keycloak
 * sin que hoy haya nadie que deba tener unos y no los otros.
 *
 * **El influencer cuelga de una cuenta** (`user_id`), que es lo que hace que la
 * app le deje publicar como tal. Al crearlo:
 * - con correo, se usa su cuenta o se le crea una **sin contraseña**
 *   (`AccountProvisioner`), que pone con «¿Has olvidado tu contraseña?». No se
 *   le manda nada: el alta la hace el equipo y avisar le toca a quien le trata.
 * - sin correo, una cuenta sin acceso, como la de la agenda de eventos: el
 *   perfil lo lleva el equipo.
 */
#[IsGranted('ROLE_BUSINESS_EDIT')]
#[Route('/api/admin/influencers', name: 'admin_influencers_')]
class AdminInfluencersController
{
    private const DEFAULT_SIZE = 20;
    private const MAX_SIZE     = 100;
    /** Lo que va en la URL del perfil: sin espacios ni tildes. */
    private const USERNAME = InfluencerProfileRules::USERNAME;
    private const NAME_MAX = InfluencerProfileRules::NAME_MAX;
    private const BIO_MAX  = InfluencerProfileRules::BIO_MAX;

    public function __construct(
        private readonly Connection $db,
        private readonly InfluencerRepository $influencers,
        private readonly UserRepository $users,
        private readonly AccountProvisioner $accounts,
        private readonly InfluencerArchiver $archiver,
        private readonly InfluencerPurger $purger,
        private readonly BunnyStorageService $storage,
        private readonly LoggerInterface $logger,
        private readonly ReviewDecisionMailer $decisions,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $status = (string) $request->query->get('status', 'active');
        $page   = max(1, (int) $request->query->get('page', 1));
        $size   = min(self::MAX_SIZE, max(1, (int) $request->query->get('size', self::DEFAULT_SIZE)));
        $q      = trim((string) $request->query->get('q', ''));

        [$where, $order] = match ($status) {
            'active'  => ['i.deleted_at IS NULL', 'i.name ASC'],
            // Los que se han dado de alta solos y esperan validación, el más
            // reciente primero.
            'pending' => ['i.deleted_at IS NULL AND i.verified_at IS NULL', 'i.created_at DESC'],
            'removed' => ['i.deleted_at IS NOT NULL', 'i.deleted_at DESC'],
            default   => [null, null],
        };
        if ($where === null) {
            return new JsonResponse(['error' => 'Unknown status. Use active, pending or removed.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $params = [];
        if ($q !== '') {
            $where .= ' AND (unaccent(lower(i.name)) LIKE unaccent(lower(?))'
                    . ' OR lower(i.username) LIKE lower(?) OR lower(u.email) LIKE lower(?))';
            $like   = '%' . $q . '%';
            $params = [$like, $like, $like];
        }

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM influencers i LEFT JOIN users u ON u.id = i.user_id WHERE {$where}",
            $params,
        );
        $rows = $this->select("WHERE {$where} ORDER BY {$order}, i.id LIMIT ? OFFSET ?", [...$params, $size, ($page - 1) * $size]);

        return new JsonResponse([
            'items' => array_map([$this, 'toItem'], $rows),
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
        ]);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function get(string $id): Response
    {
        $item = $this->find($id);

        return $item === null
            ? new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND)
            : new JsonResponse($item);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $data = $this->payload($request);

        $name     = trim((string) ($data['name'] ?? ''));
        $username = strtolower(trim((string) ($data['username'] ?? '')));
        $email    = strtolower(trim((string) ($data['email'] ?? '')));

        if ($error = $this->validateName($name) ?? $this->validateUsername($username)) {
            return $error;
        }
        if ($email !== '' && !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'invalid_email'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($email !== '') {
            // Si ya es influencer, no se le hace otro: la app sólo sabe de uno
            // por cuenta. Se mira antes de tocar Keycloak.
            $existing = $this->users->findByEmail($email);
            if ($existing !== null && $this->influencers->findByUserId($existing->getId()) !== null) {
                return new JsonResponse(['error' => 'email_has_influencer'], Response::HTTP_CONFLICT);
            }
            $user = $this->accounts->forEmail($email, $name)['user'];
        } else {
            $user = new User(id: Uuid::v4()->toRfc4122(), email: null, name: $name);
            $this->users->save($user);
        }

        $influencer = new Influencer(
            id:       Uuid::v4()->toRfc4122(),
            userId:   $user->getId(),
            username: $username,
            name:     $name,
            bio:      $this->bio($data['bio'] ?? null),
        );
        // Lo da de alta el equipo: no hay nada que revisar.
        $influencer->verify();
        $this->influencers->save($influencer);

        return new JsonResponse($this->find($influencer->getId()), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function update(string $id, Request $request): Response
    {
        $influencer = $this->influencers->findById($id);
        if ($influencer === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $data = $this->payload($request);

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($error = $this->validateName($name)) {
                return $error;
            }
            $influencer->setName($name);
        }
        if (array_key_exists('username', $data)) {
            $username = strtolower(trim((string) $data['username']));
            // Sin cambios no se valida: los heredados pueden no cumplir el
            // formato de ahora y no por eso hay que obligar a renombrarlos.
            if ($username !== $influencer->getUsername()) {
                if ($error = $this->validateUsername($username)) {
                    return $error;
                }
                $influencer->setUsername($username);
            }
        }
        if (array_key_exists('bio', $data)) {
            $influencer->setBio($this->bio($data['bio']));
        }

        $this->influencers->save($influencer);

        return new JsonResponse($this->find($id));
    }

    #[Route('/{id}/avatar', name: 'avatar', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function avatar(string $id, Request $request): Response
    {
        $influencer = $this->influencers->findById($id);
        if ($influencer === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $file = $request->files->get('file');
        if ($file === null || !$file->isValid()) {
            return new JsonResponse(['error' => 'file_required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $url = $this->storage->uploadInfluencerAvatar($id, (string) file_get_contents($file->getPathname()));
        } catch (StorageException $e) {
            return new JsonResponse(['error' => 'invalid_image', 'detail' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('Fallo subiendo el avatar de un influencer: {message}', [
                'message'    => $e->getMessage(),
                'influencer' => $id,
            ]);

            return new JsonResponse(['error' => 'upload_failed'], Response::HTTP_BAD_GATEWAY);
        }

        $previous = $influencer->getAvatar();
        $influencer->setAvatar($url);
        $this->influencers->save($influencer);
        // Después de guardar, como en el negocio: al revés, un fallo dejaría el
        // perfil apuntando a una imagen borrada.
        $this->storage->deleteByUrl($previous);

        return new JsonResponse(['url' => $url], Response::HTTP_CREATED);
    }

    #[Route(
        '/{id}/{action}',
        name: 'action',
        methods: ['PUT'],
        requirements: ['id' => '[0-9a-f-]{36}', 'action' => 'remove|restore'],
    )]
    public function act(string $id, string $action): Response
    {
        $influencer = $this->influencers->findById($id);
        if ($influencer === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        // Idempotente: archivar dos veces no mueve la fecha, que es la que
        // decide qué vídeos vuelven al recuperar.
        $cascade = match (true) {
            $action === 'remove' && !$influencer->isDeleted() => $this->archiver->archive($influencer),
            $action === 'restore' && $influencer->isDeleted() => $this->archiver->restore($influencer),
            default                                            => ['videos' => 0],
        };

        return new JsonResponse(($this->find($id) ?? []) + ['cascade' => $cascade]);
    }

    /**
     * Aprobar un creador del alta pública: sale al público y le llega el correo
     * de bienvenida a la selección (`publisherApproved`). Idempotente: aprobar
     * dos veces no vuelve a escribirle.
     */
    #[Route('/{id}/approve', name: 'approve', methods: ['PUT'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function approve(string $id): Response
    {
        $influencer = $this->influencers->findById($id);
        if ($influencer === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        if (!$influencer->isVerified()) {
            $influencer->verify();
            $this->influencers->save($influencer);
            $this->decisions->publisherApproved($id);
        }

        return new JsonResponse($this->find($id));
    }

    #[Route('/{id}', name: 'purge', methods: ['DELETE'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted('ROLE_BUSINESS_DELETE')]
    public function purge(string $id): Response
    {
        $influencer = $this->influencers->findById($id);
        if ($influencer === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        // Como en todo el panel: sólo lo que ya está en la papelera.
        if (!$influencer->isDeleted()) {
            return new JsonResponse(['error' => 'not_removed'], Response::HTTP_CONFLICT);
        }

        return new JsonResponse($this->purger->purge($influencer));
    }

    /** @return array<string, mixed>|null */
    private function find(string $id): ?array
    {
        $rows = $this->select('WHERE i.id = ?', [$id]);

        return $rows === [] ? null : $this->toItem($rows[0]);
    }

    /** @return list<array<string, mixed>> */
    private function select(string $tail, array $params): array
    {
        // Los vídeos de uno archivado son los que se fueron con él: los que
        // volverían al recuperarlo.
        return $this->db->fetchAllAssociative(
            "SELECT i.id, i.username, i.name, i.avatar, i.bio, i.meta,
                    i.created_at, i.verified_at, i.deleted_at, u.email,
                    (SELECT COUNT(*) FROM geostories g
                      WHERE g.influencer_id = i.id
                        AND (g.deleted_at IS NULL OR g.deleted_at = i.deleted_at)) AS videos,
                    (SELECT COUNT(*) FROM user_follows f
                      WHERE f.target_type = 'influencer' AND f.target_id = i.id) AS followers
               FROM influencers i
               LEFT JOIN users u ON u.id = i.user_id
               {$tail}",
            $params,
        );
    }

    /** @return array<string, mixed> */
    private function toItem(array $row): array
    {
        $meta = json_decode((string) ($row['meta'] ?? ''), true) ?: [];

        return [
            'id'          => $row['id'],
            'username'    => $row['username'],
            'name'        => $row['name'],
            'avatar'      => $row['avatar'],
            'bio'         => $row['bio'],
            'email'       => $row['email'],
            // Los que crea el sistema (la agenda de eventos) no tienen detrás a
            // una persona: se enseñan marcados.
            'system'      => $meta['system'] ?? null,
            // Las redes que dio en el alta: con ellas se comprueba quién es.
            'instagram'   => $meta['instagram'] ?? null,
            'tiktok'      => $meta['tiktok'] ?? null,
            'counts'      => ['videos' => (int) $row['videos'], 'followers' => (int) $row['followers']],
            'created_at'  => self::iso($row['created_at']),
            'verified_at' => self::iso($row['verified_at']),
            'deleted_at'  => self::iso($row['deleted_at']),
        ];
    }

    private function validateName(string $name): ?JsonResponse
    {
        return $name === '' || mb_strlen($name) > self::NAME_MAX
            ? new JsonResponse(['error' => 'invalid_name'], Response::HTTP_UNPROCESSABLE_ENTITY)
            : null;
    }

    private function validateUsername(string $username): ?JsonResponse
    {
        if (!preg_match(self::USERNAME, $username)) {
            return new JsonResponse(['error' => 'invalid_username'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($this->influencers->findByUsername($username) !== null) {
            return new JsonResponse(['error' => 'username_taken'], Response::HTTP_CONFLICT);
        }

        return null;
    }

    private function bio(mixed $bio): ?string
    {
        $bio = trim((string) $bio);

        return $bio === '' ? null : mb_substr($bio, 0, self::BIO_MAX);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $data = json_decode($request->getContent() ?: '{}', true);

        return is_array($data) ? $data : [];
    }

    private static function iso(?string $timestamp): ?string
    {
        return $timestamp === null ? null : (new \DateTimeImmutable($timestamp))->format(\DATE_ATOM);
    }
}
