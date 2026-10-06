<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * En Conciertos, «Música moderna» pasa a **Directos en sala** (06-10-2026). Mismo
 * slug (`events-small-concerts-modern`), así que lo ya clasificado se queda donde
 * estaba.
 */
final class Version20261006100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '«Música moderna» pasa a «Directos en sala»';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE categories SET name = 'Directos en sala', updated_at = NOW() WHERE slug = 'events-small-concerts-modern'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE categories SET name = 'Música moderna', updated_at = NOW() WHERE slug = 'events-small-concerts-modern'");
    }
}
