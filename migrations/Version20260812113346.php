<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260812113346 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'categories: add mode (influencer|business|both) + backfill from slug';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE categories ADD mode VARCHAR(20) DEFAULT 'business' NOT NULL");

        // Backfill existing rows by slug so the current data is not lost.
        $this->addSql("UPDATE categories SET mode = 'both' WHERE slug IN ('historicalbusiness', 'hostelry')");
        $this->addSql("UPDATE categories SET mode = 'influencer' WHERE slug IN ('place', 'events', 'news', 'nature', 'culture')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categories DROP mode');
    }
}
