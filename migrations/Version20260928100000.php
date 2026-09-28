<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La tarjeta de fidelización se enciende y se apaga desde el negocio
 * (`activated_at`: la fecha en que se encendió, o nula).
 *
 * **Todas salen encendidas**: hasta ahora no había interruptor y las que tenían
 * premios se estaban enseñando; apagadas, desaparecerían de la app de un día
 * para otro. Y las que aún no tienen premios también, porque la app publicada
 * no tiene el interruptor y no podrían encenderse al ponerlos (ver
 * `LoyaltyProgram::ACTIVE_BY_DEFAULT`). Se les pone la fecha de su última edición.
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
        $this->addSql('UPDATE loyalty_programs SET activated_at = updated_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE loyalty_programs DROP activated_at');
    }
}
