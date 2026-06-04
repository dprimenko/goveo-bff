<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260604130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Performance indexes for geostories feed queries';
    }

    // GiST CONCURRENTLY cannot run inside a transaction.
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // GiST spatial index — enables KNN <-> operator (ORDER BY distance) and ST_DWithin
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_geostories_location_gist ON geostories USING GIST (location)');

        // Partial index covering the two universal WHERE conditions — avoids full scan
        $this->addSql("CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_geostories_active ON geostories (published_at, started_at, created_at) WHERE deleted_at IS NULL AND published_at IS NOT NULL");

        // FK-style indexes for entity filters (business/influencer profile pages)
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_geostories_business_id  ON geostories (business_id)  WHERE deleted_at IS NULL');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_geostories_influencer_id ON geostories (influencer_id) WHERE deleted_at IS NULL');

        // Category join
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_geostories_category_id  ON geostories (category_id)  WHERE deleted_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_geostories_location_gist');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_geostories_active');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_geostories_business_id');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_geostories_influencer_id');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_geostories_category_id');
    }
}
