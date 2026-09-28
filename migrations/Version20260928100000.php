<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La tarjeta de fidelización se enciende y se apaga desde el negocio
 * (`activated_at`: la fecha en que se encendió, o nula).
 *
 * **Las que ya tienen premios salen encendidas**: hasta ahora no había
 * interruptor y esas tarjetas se estaban enseñando. Si salieran apagadas,
 * desaparecerían de la app de un día para otro sin que el negocio hubiera
 * tocado nada. Se les pone la fecha de su última edición.
 */
final class Version20260928100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'loyalty_programs.activated_at: el negocio enciende y apaga su tarjeta';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE loyalty_programs ADD activated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN loyalty_programs.activated_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("UPDATE loyalty_programs SET activated_at = updated_at WHERE rewards::jsonb NOT IN ('{}'::jsonb, '[]'::jsonb)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE loyalty_programs DROP activated_at');
    }
}
