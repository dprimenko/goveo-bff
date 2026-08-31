<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * billing: separa el efecto lógico de un código (qué tarifa carga) del económico
 * (qué descuento aplica), y añade el modo de facturación al plan — incluida la
 * tarifa gratis durante X días que después empieza a cobrar.
 */
final class Version20260824120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing: descuentos como entidad propia, códigos tipados y modo de facturación (free/paid/trial_then_paid) en los planes';
    }

    public function up(Schema $schema): void
    {
        // Descuentos: espejo del Coupon de Stripe, separado del código que lo entrega.
        $this->addSql(<<<'SQL'
            CREATE TABLE billing_discounts (
                id UUID NOT NULL,
                stripe_coupon_id VARCHAR(255) DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                percent_off INT DEFAULT NULL,
                amount_off_cents INT DEFAULT NULL,
                currency VARCHAR(3) NOT NULL,
                duration VARCHAR(10) NOT NULL,
                duration_in_months INT DEFAULT NULL,
                is_active BOOLEAN DEFAULT true NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_billing_discounts_stripe ON billing_discounts (stripe_coupon_id)');
        $this->addSql("COMMENT ON COLUMN billing_discounts.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN billing_discounts.updated_at IS '(DC2Type:datetimetz_immutable)'");

        // Exactamente uno de los dos: porcentaje o importe fijo.
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_discounts ADD CONSTRAINT chk_discount_one_kind
                CHECK ((percent_off IS NULL) <> (amount_off_cents IS NULL))
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_discounts ADD CONSTRAINT chk_discount_percent_range
                CHECK (percent_off IS NULL OR (percent_off BETWEEN 1 AND 100))
        SQL);

        // Un código puede cargar tarifa, aplicar descuento, atribuir partner, o combinarlos.
        $this->addSql('ALTER TABLE promo_codes ADD discount_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE promo_codes ADD partner_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE promo_codes ADD label VARCHAR(255) DEFAULT NULL');
        $this->addSql("ALTER TABLE promo_codes ALTER COLUMN plan_ids SET DEFAULT '[]'");
        $this->addSql('CREATE INDEX IDX_promo_codes_discount ON promo_codes (discount_id)');
        $this->addSql('CREATE INDEX IDX_promo_codes_partner ON promo_codes (partner_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE promo_codes ADD CONSTRAINT fk_promo_codes_discount
                FOREIGN KEY (discount_id) REFERENCES billing_discounts (id) ON DELETE SET NULL
        SQL);

        // Un código que no hace nada no debería existir.
        $this->addSql(<<<'SQL'
            ALTER TABLE promo_codes ADD CONSTRAINT chk_promo_code_has_effect
                CHECK (jsonb_array_length(plan_ids::jsonb) > 0 OR discount_id IS NOT NULL OR partner_id IS NOT NULL)
        SQL);

        // Modo de facturación del plan.
        $this->addSql("ALTER TABLE billing_plans ADD mode VARCHAR(20) DEFAULT 'paid' NOT NULL");
        $this->addSql('ALTER TABLE billing_plans ADD trial_days INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_plans ADD CONSTRAINT chk_plan_trial_days
                CHECK ((mode = 'trial_then_paid' AND trial_days >= 1) OR (mode <> 'trial_then_paid' AND trial_days IS NULL))
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_plans ADD CONSTRAINT chk_plan_free_is_zero
                CHECK (mode <> 'free' OR amount_cents = 0)
        SQL);

        // Backfill: lo que costaba 0 en Firestore era una tarifa gratuita.
        $this->addSql("UPDATE billing_plans SET mode = 'free' WHERE amount_cents = 0");

        // Todas las tarifas se cargan por código: ninguna se lista sola.
        $this->addSql('UPDATE billing_plans SET is_visible = false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_plans DROP CONSTRAINT chk_plan_free_is_zero');
        $this->addSql('ALTER TABLE billing_plans DROP CONSTRAINT chk_plan_trial_days');
        $this->addSql('ALTER TABLE billing_plans DROP COLUMN trial_days');
        $this->addSql('ALTER TABLE billing_plans DROP COLUMN mode');

        $this->addSql('ALTER TABLE promo_codes DROP CONSTRAINT chk_promo_code_has_effect');
        $this->addSql('ALTER TABLE promo_codes DROP CONSTRAINT fk_promo_codes_discount');
        $this->addSql('DROP INDEX IDX_promo_codes_partner');
        $this->addSql('DROP INDEX IDX_promo_codes_discount');
        $this->addSql('ALTER TABLE promo_codes ALTER COLUMN plan_ids DROP DEFAULT');
        $this->addSql('ALTER TABLE promo_codes DROP COLUMN label');
        $this->addSql('ALTER TABLE promo_codes DROP COLUMN partner_id');
        $this->addSql('ALTER TABLE promo_codes DROP COLUMN discount_id');

        $this->addSql('DROP TABLE billing_discounts');
    }
}
