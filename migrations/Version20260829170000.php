<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `business.welcome_email_sent_at`: cuándo salió el correo de bienvenida.
 *
 * Sin esto no hay forma de saber a quién no le llegó. Y falla en silencio por
 * diseño —el envío no puede tumbar el webhook de Stripe ni deshacer un cobro—,
 * así que sin registro el fallo no se descubre hasta que el cliente escribe
 * preguntando cómo entra.
 */
final class Version20260829170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'business: marca de envío del correo de bienvenida';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business ADD welcome_email_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN business.welcome_email_sent_at IS '(DC2Type:datetimetz_immutable)'");

        // Los 448 importados ya tenían cuenta y contraseña: nunca les tocó
        // bienvenida, y sin esto saldrían todos como pendientes de reenvío.
        $this->addSql('UPDATE business SET welcome_email_sent_at = created_at WHERE verified_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business DROP COLUMN welcome_email_sent_at');
    }
}
