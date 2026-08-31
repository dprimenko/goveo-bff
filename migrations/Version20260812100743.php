<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260812100743 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'geostories: add transcoding status + provider_video_id (Bunny Stream)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE geostories ADD status VARCHAR(20) DEFAULT 'ready' NOT NULL");
        $this->addSql('ALTER TABLE geostories ADD provider_video_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE geostories DROP status');
        $this->addSql('ALTER TABLE geostories DROP provider_video_id');
    }
}
