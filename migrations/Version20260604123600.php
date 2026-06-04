<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260604123600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add slug column to categories table (stores original Supabase text ID)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categories ADD slug VARCHAR(100) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3AF34668989D9B62 ON categories (slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_3AF34668989D9B62');
        $this->addSql('ALTER TABLE categories DROP slug');
    }
}
