<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los productos llevan **la categoría de su negocio**, sin excepción.
 *
 * Venían del import con categoría propia —un libro en una tienda de regalos era
 * `bookshop`—, 7.802 de ellos distinta de la de su tienda y otros sin ninguna,
 * y eso servía para que una tienda asomara en varias categorías de
 * descubrimiento. No se va a ofrecer: con grupos y subcategorías, un negocio
 * está en un sitio, y un producto suelto en otro contradice ese filtro.
 *
 * A partir de aquí la aplican el alta de productos y el cambio de categoría del
 * negocio; no se edita por separado.
 *
 * Irreversible: la categoría de cada producto se pierde al igualarla.
 */
final class Version20260926121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'products.category_id = la del negocio (7.802 distintas + las vacías)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE products p SET category_id = b.category_id
               FROM business b
              WHERE b.id = p.business_id AND p.category_id IS DISTINCT FROM b.category_id',
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('La categoría propia de cada producto no se guarda.');
    }
}
