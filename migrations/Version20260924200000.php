<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La activación manual de la tarjeta pasa de sí/no a **la fecha en que se
 * activó** (o nula): así se sabe también desde cuándo la tiene un negocio al que
 * se la dio el panel.
 *
 * Las que ya estaban activadas se quedan con la fecha de su última edición, que
 * es lo más cerca que hay de cuándo se activaron.
 */
final class Version20260924200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'loyalty_programs: manually_enabled (bool) → manually_enabled_at (fecha)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE loyalty_programs ADD manually_enabled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN loyalty_programs.manually_enabled_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql('UPDATE loyalty_programs SET manually_enabled_at = updated_at WHERE manually_enabled');
        $this->addSql('ALTER TABLE loyalty_programs DROP manually_enabled');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE loyalty_programs ADD manually_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('UPDATE loyalty_programs SET manually_enabled = manually_enabled_at IS NOT NULL');
        $this->addSql('ALTER TABLE loyalty_programs DROP manually_enabled_at');
    }
}
