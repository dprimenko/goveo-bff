<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «Fiestas de Madrid» pasa a **Fiestas populares** (05-10-2026): con fuentes de
 * toda la región ya no son sólo de Madrid. Mismo slug (`events-festivities`).
 * El nombre va en español y no como clave, como Conciertos: la app publicada
 * traduce la clave a «Fiestas de Madrid» y seguiría diciendo eso.
 */
final class Version20261005130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '«Fiestas de Madrid» pasa a «Fiestas populares»';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE categories SET name = 'Fiestas populares', updated_at = NOW() WHERE slug = 'events-festivities'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE categories SET name = 'category.events-festivities' WHERE slug = 'events-festivities'");
    }
}
