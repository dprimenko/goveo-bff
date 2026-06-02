<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260602143901 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE product_subcategories (id UUID NOT NULL, business_id UUID NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, sort_order INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN product_subcategories.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('CREATE TABLE products (id UUID NOT NULL, business_id UUID NOT NULL, category_id UUID DEFAULT NULL, subcategory_id UUID DEFAULT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, description_format VARCHAR(10) DEFAULT \'markdown\' NOT NULL, images JSON DEFAULT NULL, price_amount INT DEFAULT NULL, price_currency VARCHAR(3) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uq_products_business_slug ON products (business_id, slug)');
        $this->addSql('COMMENT ON COLUMN products.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN products.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN products.deleted_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN products.published_at IS \'(DC2Type:datetimetz_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('CREATE SCHEMA tiger_data');
        $this->addSql('CREATE SCHEMA tiger');
        $this->addSql('CREATE SCHEMA topology');
        $this->addSql('DROP TABLE product_subcategories');
        $this->addSql('DROP TABLE products');
    }
}
