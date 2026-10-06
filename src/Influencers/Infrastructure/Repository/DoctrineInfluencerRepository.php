<?php

declare(strict_types=1);

namespace App\Influencers\Infrastructure\Repository;

use App\Influencers\Domain\Influencer;
use App\Influencers\Domain\InfluencerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class DoctrineInfluencerRepository implements InfluencerRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?Influencer
    {
        // Como en los negocios: un id que no es un UUID —el perfil se pide
        // también por usuario— hacía lanzar a Postgres, y eso era un 500.
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->em->find(Influencer::class, $id);
    }

    public function findByUserId(string $userId): ?Influencer
    {
        return $this->em->getRepository(Influencer::class)->findOneBy(['userId' => $userId]);
    }

    public function findByUsername(string $username): ?Influencer
    {
        return $this->em->getRepository(Influencer::class)->findOneBy(['username' => $username]);
    }

    public function searchByName(?string $query, int $page, int $size, ?string $blockedBy = null): array
    {
        $conn  = $this->em->getConnection();
        // Sin validar no sale al público (los que se dan de alta solos esperan
        // a que el equipo los apruebe).
        $where = 'i.deleted_at IS NULL AND i.verified_at IS NOT NULL';
        $params = [];

        // `unaccent` para que "jose" encuentre "José". Busca en nombre y en
        // username, que es por lo que la gente conoce a un creador.
        if ($query !== null && $query !== '') {
            $where .= ' AND (unaccent(lower(i.name)) LIKE unaccent(lower(?))'
                    . ' OR unaccent(lower(i.username)) LIKE unaccent(lower(?)))';
            $like     = '%' . $query . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($blockedBy !== null) {
            $where .= " AND NOT EXISTS (SELECT 1 FROM user_blocks ub WHERE ub.user_id::text = ?"
                    . " AND ub.target_type = 'influencer' AND ub.target_id = i.id)";
            $params[] = $blockedBy;
        }

        $total = (int) $conn->fetchOne("SELECT COUNT(*) FROM influencers i WHERE {$where}", $params);

        $rows = $conn->fetchAllAssociative(
            "SELECT i.id FROM influencers i WHERE {$where} ORDER BY i.name ASC LIMIT ? OFFSET ?",
            [...$params, $size, ($page - 1) * $size],
        );

        $items = [];
        foreach ($rows as $row) {
            $influencer = $this->findById($row['id']);
            if ($influencer !== null) {
                $items[] = $influencer;
            }
        }

        return ['items' => $items, 'total' => $total];
    }

    public function save(Influencer $influencer): void
    {
        $this->em->persist($influencer);
        $this->em->flush();
    }

    public function delete(Influencer $influencer): void
    {
        $this->em->remove($influencer);
        $this->em->flush();
    }
}
