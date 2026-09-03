<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `products.meta`: cajón para lo que acompaña al producto sin ser del dominio.
 *
 * El primer inquilino es el enlace directo (`link_url` + `link_action`), que
 * lleva a la web del negocio a comprar o reservar. No es nuestro: no cobramos,
 * no reservamos y no sabemos qué hay al otro lado. Darle columnas propias sería
 * decir que sí lo es, y obligaría a una migración por cada añadido de la misma
 * clase; `business` ya resuelve lo suyo así.
 */
final class Version20260903100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'products: columna meta (enlace directo de compra/reserva)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products ADD meta JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products DROP COLUMN meta');
    }
}
