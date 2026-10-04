<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tipo de evento **Cine** (`events-cinema`).
 *
 * Hasta ahora el cine era un subnivel de «Planes y experiencias»
 * (`events-experiences-cinema`), que no sale como filtro: negocio lo quiere
 * como tipo propio. Lo ya clasificado así pasa a Cine, y el subnivel se retira
 * (borrado en blando) para que el panel no ofrezca dos sitios para lo mismo.
 *
 * Nombre en español y no clave de traducción, por lo mismo que Niños: la app
 * publicada no tiene `category.events-cinema` y pintaría el slug.
 */
final class Version20261004120000 extends AbstractMigration
{
    private const CINEMA = 'events-cinema';
    private const OLD    = 'events-experiences-cinema';

    public function getDescription(): string
    {
        return 'Tipo de evento «Cine» (events-cinema); lo que era Planes y experiencias › Cine pasa ahí';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO categories (id, name, slug, \"order\", mode, parent_id, created_at, updated_at)
             SELECT gen_random_uuid(), 'Cine', ?, 10, 'both', p.id, NOW(), NOW()
               FROM categories p
              WHERE p.slug = 'events'
                AND NOT EXISTS (SELECT 1 FROM categories WHERE slug = ?)",
            [self::CINEMA, self::CINEMA],
        );

        $this->addSql(
            'UPDATE geostories
                SET subcategory_id = (SELECT id FROM categories WHERE slug = ?), subtype_id = NULL, updated_at = NOW()
              WHERE subtype_id = (SELECT id FROM categories WHERE slug = ?)',
            [self::CINEMA, self::OLD],
        );

        $this->addSql(
            'UPDATE categories SET deleted_at = NOW(), updated_at = NOW() WHERE slug = ? AND deleted_at IS NULL',
            [self::OLD],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE categories SET deleted_at = NULL WHERE slug = ?', [self::OLD]);
        $this->addSql(
            "UPDATE geostories
                SET subcategory_id = (SELECT id FROM categories WHERE slug = 'events-experiences'),
                    subtype_id = (SELECT id FROM categories WHERE slug = ?)
              WHERE subcategory_id = (SELECT id FROM categories WHERE slug = ?)",
            [self::OLD, self::CINEMA],
        );
        $this->addSql('DELETE FROM categories WHERE slug = ?', [self::CINEMA]);
    }
}
