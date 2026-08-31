<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * leads: negocios captados por un comercial antes de existir en la app.
 *
 * Guarda los mismos campos que pide el alta para poder autorellenarla cuando el
 * cliente canjee su código. Todo opcional menos nombre y email: el comercial
 * recoge lo que puede en una visita.
 *
 * El cliente paga desde el enlace **antes** de tener cuenta, así que el lead
 * guarda el customer y la suscripción de Stripe; al registrarse, el alta vincula
 * esa suscripción en vez de volver a cobrar.
 */
final class Version20260826100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'leads: captación comercial con enlace de pago y código de canje';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE leads (
                id UUID NOT NULL,
                status VARCHAR(20) NOT NULL,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                phone VARCHAR(40) DEFAULT NULL,
                website TEXT DEFAULT NULL,
                booking_url TEXT DEFAULT NULL,
                address TEXT DEFAULT NULL,
                latitude DOUBLE PRECISION DEFAULT NULL,
                longitude DOUBLE PRECISION DEFAULT NULL,
                category_id UUID DEFAULT NULL,
                billing JSON DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                billing_plan_id UUID DEFAULT NULL,
                amount_cents_override INT DEFAULT NULL,
                stripe_price_id VARCHAR(255) DEFAULT NULL,
                stripe_payment_link_id VARCHAR(255) DEFAULT NULL,
                payment_url TEXT DEFAULT NULL,
                stripe_customer_id VARCHAR(255) DEFAULT NULL,
                stripe_subscription_id VARCHAR(255) DEFAULT NULL,
                promo_code_id UUID DEFAULT NULL,
                business_id UUID DEFAULT NULL,
                created_by UUID DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                paid_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        foreach (['created_at', 'updated_at', 'paid_at'] as $column) {
            $this->addSql("COMMENT ON COLUMN leads.$column IS '(DC2Type:datetimetz_immutable)'");
        }

        // Un código pertenece como mucho a un lead: es de un solo uso y no se
        // reparte entre clientes.
        $this->addSql('CREATE UNIQUE INDEX UNIQ_leads_promo_code ON leads (promo_code_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_leads_payment_link ON leads (stripe_payment_link_id)');
        // El webhook busca por aquí, y llega una vez por cada evento de Stripe.
        $this->addSql('CREATE UNIQUE INDEX UNIQ_leads_subscription ON leads (stripe_subscription_id)');
        $this->addSql('CREATE INDEX IDX_leads_status_created ON leads (status, created_at DESC)');

        $this->addSql(<<<'SQL'
            ALTER TABLE leads ADD CONSTRAINT fk_leads_promo_code
                FOREIGN KEY (promo_code_id) REFERENCES promo_codes (id) ON DELETE SET NULL
        SQL);

        // Un importe pactado negativo sería un abono, no una tarifa.
        $this->addSql(<<<'SQL'
            ALTER TABLE leads ADD CONSTRAINT chk_leads_amount_override
                CHECK (amount_cents_override IS NULL OR amount_cents_override >= 0)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE leads');
    }
}
