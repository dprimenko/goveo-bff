<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `business_subscriptions.invited_at`: tarifa de invitación, dada por el equipo
 * sin cobrarla por aquí (el cliente pagó por un enlace externo, o se le regala).
 * Nula o la fecha en que se dio; ver `PlanOffer`.
 */
final class Version20260930100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'business_subscriptions.invited_at: tarifas de invitación, activas sin cobro';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business_subscriptions ADD invited_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business_subscriptions DROP invited_at');
    }
}
