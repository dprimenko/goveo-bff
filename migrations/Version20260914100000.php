<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `business.city`: la ciudad, en su propia columna.
 *
 * Hasta ahora la ciudad sólo existía dentro de `meta->>'address'`, que es el
 * texto que devuelve Google («C. Butrón, 27, 28022 Madrid, Spain»). Filtrar por
 * ciudad sobre eso serían expresiones regulares repetidas en cada consulta, sin
 * índice y sin poder listar las ciudades que hay — que es justo lo que pide el
 * desplegable del panel.
 *
 * **La columna nace vacía a propósito.** Rellenarla es geocodificar contra
 * Google, que es red y es dinero, y eso no puede pasar dentro de un despliegue:
 * lo hace `goveo:business:backfill-cities`, que se lanza a mano, es idempotente
 * y sólo mira a quien todavía no la tiene.
 *
 * El índice es un btree normal sobre el texto tal cual: el valor del filtro no
 * lo teclea nadie, sale del propio desplegable —que se construye con un `GROUP
 * BY` de esta columna—, así que la comparación es exacta y no hace falta ni
 * `unaccent` ni `lower`. Con `unaccent` además no se podría: no es inmutable y
 * Postgres no la admite en un índice sin envolverla.
 */
final class Version20260914100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'business: columna city (se rellena con goveo:business:backfill-cities)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business ADD city VARCHAR(120) DEFAULT NULL');

        $this->addSql('
            CREATE INDEX idx_business_city
                ON business (city)
             WHERE deleted_at IS NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_business_city');
        $this->addSql('ALTER TABLE business DROP city');
    }
}
