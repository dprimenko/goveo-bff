<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A quién se le ofreció una tarifa desde el panel (`PlanOffer`): si se repite
 * con otro correo antes de que pague —porque el primero estaba mal—, al
 * anterior se le quita el acceso al negocio.
 */
final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'business_subscriptions.offered_user_id: a quién se ofreció la tarifa desde el panel';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business_subscriptions ADD offered_user_id UUID DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business_subscriptions DROP offered_user_id');
    }
}
