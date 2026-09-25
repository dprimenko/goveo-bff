<?php

declare(strict_types=1);

namespace App\Categories\Application;

use Doctrine\DBAL\Connection;

/**
 * Las subcategorías: categorías hijas de otra (`categories.parent_id`). Hoy sólo
 * las tiene Eventos.
 *
 * Una publicación sólo lleva subcategoría si su categoría tiene hijas, y sólo
 * una de **sus** hijas: «Flamenco» en una noticia no significa nada.
 *
 * Si no llega ninguna —o llega una que no vale—, va a la de por defecto
 * (`<padre>-other`, «Otros»). Así lo que publican las apps anteriores a esto,
 * el panel sin tocar el campo o el scraping entra igual y se reclasifica
 * después, en vez de fallar o quedarse fuera de cualquier filtro.
 */
final class Subcategories
{
    /** @var array<string, list<array{id: string, slug: string}>> hijas por id de padre */
    private array $children = [];

    public function __construct(private readonly Connection $db) {}

    /**
     * La subcategoría que corresponde, o `null` si la categoría no tiene hijas.
     *
     * @param string|null $requested id o slug de la pedida
     */
    public function resolve(?string $categoryId, ?string $requested): ?string
    {
        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        $children = $this->childrenOf($categoryId);
        if ($children === []) {
            return null;
        }

        $requested = trim((string) $requested);
        foreach ($children as $child) {
            if ($requested !== '' && ($child['id'] === $requested || $child['slug'] === $requested)) {
                return $child['id'];
            }
        }

        foreach ($children as $child) {
            if (str_ends_with($child['slug'], '-other')) {
                return $child['id'];
            }
        }

        return $children[0]['id'];
    }

    /** @return list<array{id: string, slug: string}> */
    private function childrenOf(string $categoryId): array
    {
        return $this->children[$categoryId] ??= $this->db->fetchAllAssociative(
            'SELECT id, slug FROM categories WHERE parent_id = ? AND deleted_at IS NULL ORDER BY "order"',
            [$categoryId],
        );
    }
}
