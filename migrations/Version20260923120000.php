<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `geostories.external_ref`: de dónde vino lo que importa el scraping de eventos.
 *
 * Único **sin** excluir lo borrado: un evento descartado en el panel tiene que
 * seguir descartado en la siguiente pasada del cron, no volver a la cola. Los
 * nulos —todo lo que sube la gente— no chocan entre sí.
 */
final class Version20260923120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'geostories: external_ref único para no importar dos veces el mismo evento';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE geostories ADD external_ref VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_geostories_external_ref ON geostories (external_ref)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_geostories_external_ref');
        $this->addSql('ALTER TABLE geostories DROP external_ref');
    }
}
