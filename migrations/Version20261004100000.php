<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tipo de evento **Niños** (`events-kids`), con sus subniveles.
 *
 * Lo pidió negocio con una tanda de fuentes infantiles (teatro de títeres,
 * cuentacuentos de las bibliotecas, talleres del Price…). Va delante de
 * «Otros», que es siempre el último.
 *
 * **Nombre en español, no clave de traducción**: la app publicada no tiene
 * `category.events-kids` y pintaría el slug; con el nombre tal cual sale
 * «Niños» (`categoryLabel` lo deja pasar), como los grupos de categorías. Los
 * subniveles no se ven como filtro, pero el panel los enseña.
 */
final class Version20261004100000 extends AbstractMigration
{
    private const KIDS = 'events-kids';

    /** subnivel => nombre, en su orden. */
    private const SUBTYPES = [
        'events-kids-theater'      => 'Teatro y títeres',
        'events-kids-workshops'    => 'Talleres',
        'events-kids-storytelling' => 'Cuentacuentos',
        'events-kids-family-plans' => 'Planes en familia',
    ];

    public function getDescription(): string
    {
        return 'Tipo de evento «Niños» (events-kids) y sus subniveles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO categories (id, name, slug, \"order\", mode, parent_id, created_at, updated_at)
             SELECT gen_random_uuid(), 'Niños', ?, 9, 'both', p.id, NOW(), NOW()
               FROM categories p
              WHERE p.slug = 'events'
                AND NOT EXISTS (SELECT 1 FROM categories WHERE slug = ?)",
            [self::KIDS, self::KIDS],
        );

        $order = 0;
        foreach (self::SUBTYPES as $slug => $name) {
            $this->addSql(
                "INSERT INTO categories (id, name, slug, \"order\", mode, parent_id, created_at, updated_at)
                 SELECT gen_random_uuid(), ?, ?, ?, 'both', p.id, NOW(), NOW()
                   FROM categories p
                  WHERE p.slug = ?
                    AND NOT EXISTS (SELECT 1 FROM categories WHERE slug = ?)",
                [$name, $slug, ++$order, self::KIDS, $slug],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Lo que tuviera estos tipos vuelve a «Otros» y sin subnivel.
        $this->addSql(
            "UPDATE geostories
                SET subcategory_id = (SELECT id FROM categories WHERE slug = 'events-other'), subtype_id = NULL
              WHERE subcategory_id = (SELECT id FROM categories WHERE slug = ?)",
            [self::KIDS],
        );
        $slugs = [...array_keys(self::SUBTYPES), self::KIDS];
        $this->addSql(
            'DELETE FROM categories WHERE slug IN (' . implode(', ', array_fill(0, count($slugs), '?')) . ')',
            $slugs,
        );
    }
}
