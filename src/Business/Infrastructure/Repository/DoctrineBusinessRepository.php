<?php

declare(strict_types=1);

namespace App\Business\Infrastructure\Repository;

use App\Business\Domain\Business;
use App\Business\Domain\BusinessRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class DoctrineBusinessRepository implements BusinessRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?Business
    {
        // Las rutas públicas aceptan id **o** slug y prueban primero por id. Con
        // un slug, Postgres reventaba con «invalid input syntax for type uuid»
        // antes de llegar a la segunda opción, así que `/public/businesses/{slug}`
        // devolvía 500 y nunca funcionó. Un id que no es UUID no existe: null.
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->em->find(Business::class, $id);
    }

    public function findBySlug(string $slug): ?Business
    {
        return $this->em->getRepository(Business::class)->findOneBy(['slug' => $slug]);
    }

    public function findByCreatorId(string $creatorId): array
    {
        return $this->em->getRepository(Business::class)->findBy(['creatorId' => $creatorId]);
    }

    public function findNearby(
        float $latitude,
        float $longitude,
        int $page,
        int $size,
        ?array $categoryIds = null,
        ?array $excludeCategoryIds = null,
        ?float $radiusMeters = null,
        ?string $query = null,
    ): array {
        $conn = $this->em->getConnection();

        // `verified_at IS NOT NULL` es lo que mantiene fuera del feed, el mapa y
        // la búsqueda a los negocios recién dados de alta: existen y su dueño los
        // ve en su listado, pero no se publican hasta que alguien de Goveo los
        // revisa. Los 448 importados ya venían validados.
        $where       = "b.deleted_at IS NULL AND b.location IS NOT NULL AND b.verified_at IS NOT NULL";
        $countParams = [];
        $dataParams  = [$longitude, $latitude];

        if (!empty($categoryIds)) {
            // `IN` con un `?` por id: los parámetros van posicionales en el
            // resto de la consulta, y mezclar nombrados aquí obligaría a
            // reescribirla entera.
            $where .= ' AND b.category_id IN (' . implode(', ', array_fill(0, count($categoryIds), '?')) . ')';
            foreach ($categoryIds as $id) {
                $countParams[] = $id;
                $dataParams[]  = $id;
            }
        }

        if (!empty($excludeCategoryIds)) {
            // `IS NULL` incluido: un negocio sin categoría no está en el grupo
            // excluido, y `NOT IN` por sí solo lo dejaría fuera —en SQL,
            // comparar con NULL no es ni verdadero ni falso—, así que
            // desaparecería del listado sin que nadie lo hubiera pedido.
            $where .= ' AND (b.category_id IS NULL OR b.category_id NOT IN ('
                    . implode(', ', array_fill(0, count($excludeCategoryIds), '?')) . '))';
            foreach ($excludeCategoryIds as $id) {
                $countParams[] = $id;
                $dataParams[]  = $id;
            }
        }

        // Búsqueda por nombre: `unaccent` para que "jamoneria" encuentre
        // "Jamonería", que en español es lo que espera cualquiera.
        if ($query !== null && $query !== '') {
            $where .= ' AND unaccent(lower(b.name)) LIKE unaccent(lower(?))';
            $like = '%' . $query . '%';
            $countParams[] = $like;
            $dataParams[]  = $like;
        }

        // Acota a lo que se ve en el mapa. ST_DWithin sobre geography usa el
        // índice GiST de business.location, así que no cuesta un scan.
        if ($radiusMeters !== null) {
            $where .= ' AND ST_DWithin(b.location::geography,'
                    . ' ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)';

            foreach ([$longitude, $latitude, $radiusMeters] as $param) {
                $countParams[] = $param;
                $dataParams[]  = $param;
            }
        }

        $countSql = "SELECT COUNT(*) FROM business b WHERE {$where}";
        $total    = (int) $conn->fetchOne($countSql, $countParams);

        $dataSql = "
            SELECT b.id,
                   ST_Y(b.location::geometry) AS lat,
                   ST_X(b.location::geometry) AS long,
                   ST_Distance(
                       b.location::geography,
                       ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                   ) AS dist_meters
            FROM business b
            WHERE {$where}
            ORDER BY dist_meters ASC
            LIMIT ? OFFSET ?
        ";

        $dataParams[] = $size;
        $dataParams[] = ($page - 1) * $size;
        $rows         = $conn->fetchAllAssociative($dataSql, $dataParams);

        $items = [];
        foreach ($rows as $row) {
            $business = $this->findById($row['id']);
            if ($business !== null) {
                $items[] = [
                    'business'    => $business,
                    'dist_meters' => (float) $row['dist_meters'],
                    'lat'         => (float) $row['lat'],
                    'long'        => (float) $row['long'],
                ];
            }
        }

        return ['items' => $items, 'total' => $total];
    }

    public function save(Business $business): void
    {
        $this->em->persist($business);
        $this->em->flush();
    }

    public function delete(Business $business): void
    {
        $this->em->remove($business);
        $this->em->flush();
    }
}
