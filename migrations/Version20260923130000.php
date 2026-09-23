<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `business.external_ref`: la sala que creó el scraping de eventos.
 *
 * Único **sin** excluir lo borrado: una sala descartada en el panel sigue
 * descartada en la siguiente pasada del cron. Los nulos —todo negocio dado de
 * alta de otra forma— no chocan entre sí.
 */
final class Version20260923130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'business: external_ref único para no crear dos veces la misma sala desde el scraping';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business ADD external_ref VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_business_external_ref ON business (external_ref)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_business_external_ref');
        $this->addSql('ALTER TABLE business DROP external_ref');
    }
}
