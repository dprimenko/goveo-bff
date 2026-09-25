<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Subcategorías de Eventos, como **categorías hijas**.
 *
 * - `categories.parent_id`: el árbol de categorías. Hoy sólo cuelgan hijas de
 *   `events`, pero es la pieza que necesita la reestructuración de categorías
 *   que viene después, y admite más niveles sin tocar el esquema.
 * - `geostories.subcategory_id`: la subcategoría de un evento. Va **aparte** de
 *   `category_id` y no en su lugar: todo lo que decide si algo es un evento
 *   —la vigencia, el feed de Eventos, el enlace externo— mira `cat.slug =
 *   'events'`, y cambiarle la categoría a «Escena» lo sacaría de ahí.
 * - Los eventos que ya existen van a «Otros»: se reclasifican desde el panel.
 *
 * «Conciertos grandes» no entra por ahora: se comparte con demasiados sitios y
 * no es el hueco de Goveo.
 */
final class Version20260926090000 extends AbstractMigration
{
    /** slug => orden. El nombre es la clave de traducción `category.<slug>`. */
    private const SUBCATEGORIES = [
        'events-small-concerts' => 1,
        'events-nightlife'      => 2,
        'events-stage'          => 3,
        'events-flamenco'       => 4,
        'events-art'            => 5,
        'events-markets'        => 6,
        'events-festivities'    => 7,
        'events-experiences'    => 8,
        'events-other'          => 99,
    ];

    public function getDescription(): string
    {
        return 'categories.parent_id + subcategorías de Eventos; geostories.subcategory_id (lo existente, a «Otros»)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categories ADD parent_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_categories_parent_id ON categories (parent_id)');
        $this->addSql('ALTER TABLE geostories ADD subcategory_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_geostories_subcategory_id ON geostories (subcategory_id) WHERE deleted_at IS NULL');

        foreach (self::SUBCATEGORIES as $slug => $order) {
            // `mode` both: suben eventos tanto influencers como negocios.
            $this->addSql(
                "INSERT INTO categories (id, name, slug, \"order\", mode, parent_id, created_at, updated_at)
                 SELECT gen_random_uuid(), ?, ?, ?, 'both', p.id, NOW(), NOW()
                   FROM categories p
                  WHERE p.slug = 'events'
                    AND NOT EXISTS (SELECT 1 FROM categories WHERE slug = ?)",
                ['category.' . $slug, $slug, $order, $slug],
            );
        }

        $this->addSql(
            "UPDATE geostories g
                SET subcategory_id = (SELECT id FROM categories WHERE slug = 'events-other')
               FROM categories c
              WHERE c.id = g.category_id AND c.slug = 'events' AND g.subcategory_id IS NULL",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_geostories_subcategory_id');
        $this->addSql('ALTER TABLE geostories DROP subcategory_id');
        $this->addSql("DELETE FROM categories WHERE slug LIKE 'events-%' AND parent_id IS NOT NULL");
        $this->addSql('DROP INDEX idx_categories_parent_id');
        $this->addSql('ALTER TABLE categories DROP parent_id');
    }
}
