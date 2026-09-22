<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `product_subcategories.kind`: la subcategoría de promociones.
 *
 * Todos los negocios van a tener una «Promos» para poder publicar una oferta
 * sin inventarse antes dónde meterla, y el sistema de ofertas que venga después
 * necesita encontrarla **sin depender del nombre**: el nombre lo puede cambiar
 * quien gestiona la tienda, y además es una clave de traducción.
 *
 * El índice único parcial es lo que impide que un negocio acabe con dos: la fila
 * se crea al vuelo la primera vez que hace falta, y sin esto dos peticiones a la
 * vez —abrir la gestión del catálogo en dos sitios— crearían una cada una.
 *
 * Nace todo como `custom`, que es lo que había: ninguna consulta anterior cambia
 * de resultado.
 */
final class Version20260922180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'product_subcategories.kind (custom|promos) + una sola «promos» por negocio';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE product_subcategories
                ADD COLUMN IF NOT EXISTS kind VARCHAR(20) NOT NULL DEFAULT 'custom'
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_subcategory_promos_per_business
                ON product_subcategories (business_id)
                WHERE kind = 'promos'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_subcategory_promos_per_business');
        $this->addSql('ALTER TABLE product_subcategories DROP COLUMN IF EXISTS kind');
    }
}
