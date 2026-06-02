<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260602140648 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE business (id UUID NOT NULL, slug VARCHAR(255) NOT NULL, name VARCHAR(255) DEFAULT NULL, description TEXT DEFAULT NULL, avatar TEXT DEFAULT NULL, main_image TEXT DEFAULT NULL, category_id UUID NOT NULL, creator_id UUID NOT NULL, partner_id UUID DEFAULT NULL, meta JSON DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D36E38989D9B62 ON business (slug)');
        $this->addSql('COMMENT ON COLUMN business.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN business.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN business.deleted_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN business.verified_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('CREATE TABLE business_managers (user_id UUID NOT NULL, business_id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(user_id, business_id))');
        $this->addSql('COMMENT ON COLUMN business_managers.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN business_managers.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN business_managers.deleted_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('CREATE TABLE categories (id UUID NOT NULL, name VARCHAR(255) DEFAULT NULL, image TEXT DEFAULT NULL, "order" INT DEFAULT NULL, partner VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN categories.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN categories.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN categories.deleted_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('CREATE TABLE categories_category_types (category_id UUID NOT NULL, type_id UUID NOT NULL, PRIMARY KEY(category_id, type_id))');
        $this->addSql('CREATE TABLE categories_statistics (category_id UUID NOT NULL, business_counter INT DEFAULT 0 NOT NULL, geostories_counter INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(category_id))');
        $this->addSql('COMMENT ON COLUMN categories_statistics.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN categories_statistics.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('CREATE TABLE category_types (id UUID NOT NULL, name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN category_types.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN category_types.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN category_types.deleted_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('CREATE TABLE default_subcategories (id UUID NOT NULL, category_id UUID DEFAULT NULL, name VARCHAR(255) NOT NULL, icon TEXT DEFAULT NULL, "order" INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN default_subcategories.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN default_subcategories.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN default_subcategories.deleted_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('CREATE TABLE geostories (id UUID NOT NULL, title VARCHAR(255) DEFAULT NULL, description TEXT DEFAULT NULL, thumbnail TEXT NOT NULL, url TEXT NOT NULL, likes INT DEFAULT 0 NOT NULL, views INT DEFAULT 0 NOT NULL, location geometry(POINT, 4326) DEFAULT NULL, category_id UUID DEFAULT NULL, influencer_id UUID DEFAULT NULL, business_id UUID DEFAULT NULL, is_main BOOLEAN DEFAULT false NOT NULL, meta JSON DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ended_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN geostories.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN geostories.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN geostories.deleted_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN geostories.verified_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN geostories.published_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN geostories.started_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN geostories.ended_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('CREATE TABLE influencers (id UUID NOT NULL, user_id UUID NOT NULL, username VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, avatar TEXT DEFAULT NULL, bio TEXT DEFAULT NULL, meta JSON DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_54ECA2D5F85E0677 ON influencers (username)');
        $this->addSql('COMMENT ON COLUMN influencers.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN influencers.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN influencers.deleted_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN influencers.verified_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('CREATE TABLE partner_zipcodes (id UUID NOT NULL, partner_id UUID NOT NULL, zipcode VARCHAR(20) NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN partner_zipcodes.deleted_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('CREATE TABLE partners (id UUID NOT NULL, name VARCHAR(255) NOT NULL, meta JSON DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN partners.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN partners.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN partners.deleted_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('CREATE TABLE user_notifications_devices (id UUID NOT NULL, user_id UUID DEFAULT NULL, device_id VARCHAR(255) NOT NULL, device_info TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN user_notifications_devices.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN user_notifications_devices.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, email VARCHAR(255) DEFAULT NULL, name VARCHAR(255) DEFAULT NULL, profile_image TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('COMMENT ON COLUMN users.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN users.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN users.deleted_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN users.verified_at IS \'(DC2Type:datetimetz_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('CREATE SCHEMA tiger_data');
        $this->addSql('CREATE SCHEMA tiger');
        $this->addSql('CREATE SCHEMA topology');
        $this->addSql('DROP TABLE business');
        $this->addSql('DROP TABLE business_managers');
        $this->addSql('DROP TABLE categories');
        $this->addSql('DROP TABLE categories_category_types');
        $this->addSql('DROP TABLE categories_statistics');
        $this->addSql('DROP TABLE category_types');
        $this->addSql('DROP TABLE default_subcategories');
        $this->addSql('DROP TABLE geostories');
        $this->addSql('DROP TABLE influencers');
        $this->addSql('DROP TABLE partner_zipcodes');
        $this->addSql('DROP TABLE partners');
        $this->addSql('DROP TABLE user_notifications_devices');
        $this->addSql('DROP TABLE users');
    }
}
