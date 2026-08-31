<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El alta de negocio pasa a la web y deja de haber "leads".
 *
 * Antes el comercial guardaba un lead, el cliente pagaba y un código de canje
 * convertía ese lead en negocio al registrarse en la app. Ahora el formulario es
 * público y **el negocio nace al enviarlo**, sin validar y sin pagar, así que no
 * hay ninguna entidad intermedia: lo que estaba en `leads` vive en el propio
 * negocio y en su suscripción.
 *
 * Con ello se cae el canje por código (`Version20260826100000`), que sólo existía
 * para reconstruir el negocio después del pago.
 */
final class Version20260827100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'alta web: el cobro pendiente vive en business_subscriptions; se elimina leads';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business_subscriptions ADD stripe_payment_link_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE business_subscriptions ADD payment_url TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE business_subscriptions ADD stripe_customer_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE business_subscriptions ADD stripe_price_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE business_subscriptions ADD amount_cents INT DEFAULT NULL');

        // Una suscripción pendiente de pago todavía no tiene periodo: ponerle
        // «ahora» sería inventarse una fecha que luego alguien leería como real.
        $this->addSql('ALTER TABLE business_subscriptions ALTER COLUMN current_period_start DROP NOT NULL');
        $this->addSql('ALTER TABLE business_subscriptions ALTER COLUMN current_period_end DROP NOT NULL');

        // El webhook busca por aquí para atribuir el cobro.
        $this->addSql('CREATE UNIQUE INDEX UNIQ_subscriptions_payment_link ON business_subscriptions (stripe_payment_link_id)');

        $this->addSql('DROP TABLE IF EXISTS leads');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_subscriptions_payment_link');
        foreach (['amount_cents', 'stripe_price_id', 'stripe_customer_id', 'payment_url', 'stripe_payment_link_id'] as $column) {
            $this->addSql("ALTER TABLE business_subscriptions DROP COLUMN $column");
        }
        // Los periodos no se devuelven a NOT NULL: podría haber filas sin ellos.
    }
}
