<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260602151902 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE billing_plans (id UUID NOT NULL, billing_product_id UUID NOT NULL, stripe_price_id VARCHAR(255) DEFAULT NULL, stripe_payment_link TEXT DEFAULT NULL, name VARCHAR(255) NOT NULL, amount_cents INT NOT NULL, currency VARCHAR(3) NOT NULL, interval VARCHAR(10) NOT NULL, interval_count INT DEFAULT 1 NOT NULL, commission_percent INT DEFAULT 0 NOT NULL, is_visible BOOLEAN DEFAULT true NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3018BDFC8B531BD4 ON billing_plans (stripe_price_id)');
        $this->addSql('COMMENT ON COLUMN billing_plans.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN billing_plans.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('CREATE TABLE billing_products (id UUID NOT NULL, stripe_product_id VARCHAR(255) DEFAULT NULL, name VARCHAR(255) NOT NULL, types JSON DEFAULT \'[]\' NOT NULL, description JSON DEFAULT NULL, sort_order INT DEFAULT 0 NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AB6633A63E8AC0D2 ON billing_products (stripe_product_id)');
        $this->addSql('COMMENT ON COLUMN billing_products.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN billing_products.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('CREATE TABLE business_subscriptions (id UUID NOT NULL, business_id UUID NOT NULL, billing_plan_id UUID NOT NULL, stripe_subscription_id VARCHAR(255) DEFAULT NULL, promo_code_id UUID DEFAULT NULL, status VARCHAR(20) NOT NULL, current_period_start TIMESTAMP(0) WITH TIME ZONE NOT NULL, current_period_end TIMESTAMP(0) WITH TIME ZONE NOT NULL, cancelled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_565C401AB5DBB761 ON business_subscriptions (stripe_subscription_id)');
        $this->addSql('COMMENT ON COLUMN business_subscriptions.current_period_start IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN business_subscriptions.current_period_end IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN business_subscriptions.cancelled_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN business_subscriptions.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN business_subscriptions.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('CREATE TABLE promo_codes (id UUID NOT NULL, code VARCHAR(100) NOT NULL, plan_ids JSON NOT NULL, max_uses INT DEFAULT NULL, used_count INT DEFAULT 0 NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C84FDDB77153098 ON promo_codes (code)');
        $this->addSql('COMMENT ON COLUMN promo_codes.expires_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN promo_codes.created_at IS \'(DC2Type:datetimetz_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('CREATE SCHEMA tiger_data');
        $this->addSql('CREATE SCHEMA tiger');
        $this->addSql('CREATE SCHEMA topology');
        $this->addSql('DROP TABLE billing_plans');
        $this->addSql('DROP TABLE billing_products');
        $this->addSql('DROP TABLE business_subscriptions');
        $this->addSql('DROP TABLE promo_codes');
    }
}
