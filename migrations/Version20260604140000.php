<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260604140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add PostGIS location column to business table, backfilled from meta.geohash';
    }

    // CONCURRENTLY index cannot run inside a transaction.
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Add PostGIS geometry column
        $this->addSql('ALTER TABLE business ADD COLUMN location geometry(POINT, 4326) DEFAULT NULL');

        // Backfill from meta->>'geohash' for all existing rows that have it
        $this->addSql(
            "UPDATE business
             SET location = ST_SetSRID(ST_PointFromGeoHash(meta->>'geohash'), 4326)
             WHERE meta->>'geohash' IS NOT NULL"
        );

        // GiST spatial index — enables KNN <-> operator and ST_DWithin
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_business_location_gist ON business USING GIST (location)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_business_location_gist');
        $this->addSql('ALTER TABLE business DROP COLUMN location');
    }
}
