<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «Cultura» para negocios: `culture-business`.
 *
 * Ya había una `culture`, pero es de influencer —de las que caducan en el feed
 * de lugares— y no tiene nada que ver con esta: aquí van museos, galerías,
 * teatros… negocios con ficha y catálogo. Mismo caso que `events` / `eventss`.
 *
 * Se **reaprovechan el nombre y la imagen**: el nombre es la clave
 * `category.culture-business`, que se traduce igual («Cultura»), y la imagen se
 * copia de la fila `culture` de cada base en vez de escribir la URL, así sale
 * la que haya en cada entorno.
 *
 * El id es el UUID v5 de su slug con el namespace de las importaciones, como el
 * resto del catálogo. Va en el tipo `523d6611…`, que es el que pide la home para
 * los círculos de Comercio local (y el mapa); sin él no saldría en ninguno.
 * `mode` = business: `Category::modeForSlug` ya lo da por defecto.
 */
final class Version20260923100000 extends AbstractMigration
{
    private const ID = '7ec3fb98-daa0-5ce0-bdde-f3b3f97bdbc6';
    private const RETAIL_TYPE = '523d6611-8d0c-5241-961f-4e3ec74b129a';

    public function getDescription(): string
    {
        return 'categories: «Cultura» de negocio (culture-business)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf(<<<'SQL'
            INSERT INTO categories (id, name, slug, image, "order", partner, mode)
            VALUES (
                '%s',
                'category.culture-business',
                'culture-business',
                (SELECT image FROM categories WHERE slug = 'culture' LIMIT 1),
                126,
                NULL,
                'business'
            )
            ON CONFLICT (id) DO NOTHING
        SQL, self::ID));

        $this->addSql(sprintf(<<<'SQL'
            INSERT INTO categories_category_types (category_id, type_id)
            SELECT '%s', id FROM category_types WHERE id = '%s'
            ON CONFLICT DO NOTHING
        SQL, self::ID, self::RETAIL_TYPE));
    }

    public function down(Schema $schema): void
    {
        $this->addSql(sprintf("DELETE FROM categories_category_types WHERE category_id = '%s'", self::ID));
        $this->addSql(sprintf("DELETE FROM categories WHERE id = '%s'", self::ID));
    }
}
