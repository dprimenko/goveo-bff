<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tipo de evento **Circo** (`events-circus`).
 *
 * Hasta ahora el circo iba con la magia, en Escena › Magia. Lo ya importado en
 * Escena que lleva «circo» en el título pasa a Circo; el resto se corrige en el
 * panel. Nombre en español, como Niños y Cine (ver `Version20261004100000`).
 */
final class Version20261004130000 extends AbstractMigration
{
    private const CIRCUS = 'events-circus';

    public function getDescription(): string
    {
        return 'Tipo de evento «Circo» (events-circus); lo de Escena con «circo» en el título pasa ahí';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO categories (id, name, slug, \"order\", mode, parent_id, created_at, updated_at)
             SELECT gen_random_uuid(), 'Circo', ?, 11, 'both', p.id, NOW(), NOW()
               FROM categories p
              WHERE p.slug = 'events'
                AND NOT EXISTS (SELECT 1 FROM categories WHERE slug = ?)",
            [self::CIRCUS, self::CIRCUS],
        );

        $this->addSql(
            "UPDATE geostories
                SET subcategory_id = (SELECT id FROM categories WHERE slug = ?), subtype_id = NULL, updated_at = NOW()
              WHERE subcategory_id = (SELECT id FROM categories WHERE slug = 'events-stage')
                AND title ~* '\\mcirco\\M'",
            [self::CIRCUS],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE geostories
                SET subcategory_id = (SELECT id FROM categories WHERE slug = 'events-stage'),
                    subtype_id = (SELECT id FROM categories WHERE slug = 'events-stage-magic')
              WHERE subcategory_id = (SELECT id FROM categories WHERE slug = ?)",
            [self::CIRCUS],
        );
        $this->addSql('DELETE FROM categories WHERE slug = ?', [self::CIRCUS]);
    }
}
