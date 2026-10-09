<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Paso a la app (09-10-2026): el recuento de instalaciones que Branch atribuye a
 * un enlace, por día y origen. Sólo agregados —ni dispositivo, ni usuario, ni
 * IP—; ver `App\Installs` y «Paso a la app» en CLAUDE.md.
 */
final class Version20261009100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'paso a la app: app_installs_daily (instalaciones atribuidas por Branch, por día y origen)';
    }

    public function up(Schema $schema): void
    {
        // La combinación entera es la clave: contar una más es `installs + 1`.
        // Lo que no se sabe va como cadena vacía, no `NULL`, porque forma parte
        // de la clave.
        $this->addSql(<<<'SQL'
            CREATE TABLE app_installs_daily (
                day DATE NOT NULL,
                platform VARCHAR(10) NOT NULL,
                channel VARCHAR(32) NOT NULL,
                feature VARCHAR(32) NOT NULL,
                campaign VARCHAR(64) NOT NULL,
                kind VARCHAR(16) NOT NULL,
                installs INT DEFAULT 0 NOT NULL,
                PRIMARY KEY(day, platform, channel, feature, campaign, kind)
            )
        SQL);

        $this->addSql("COMMENT ON COLUMN app_installs_daily.day IS '(DC2Type:date_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_installs_daily');
    }
}
