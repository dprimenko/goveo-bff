<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `billing_plans.code`: identificador legible para las URLs.
 *
 * Los enlaces de la landing los pega gente a mano en botones, así que llevan
 * `?plan=platinum-anual` y no un UUID. Además sobrevive a recrear el plan: el
 * UUID cambiaría y el enlace quedaría roto sin que nadie se entere.
 */
final class Version20260827160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing_plans: código legible para los enlaces de la landing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_plans ADD code VARCHAR(60) DEFAULT NULL');
        // Nullable porque las 58 heredadas no lo tienen ni lo necesitan: no se
        // ofrecen. Único para que dos planes no se peleen por el mismo enlace.
        $this->addSql('CREATE UNIQUE INDEX UNIQ_billing_plans_code ON billing_plans (code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_billing_plans_code');
        $this->addSql('ALTER TABLE billing_plans DROP COLUMN code');
    }
}
