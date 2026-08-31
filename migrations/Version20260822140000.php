<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260822140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'likes: geostory_likes (like por usuario, sobre la base heredada de geostories.likes)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE geostory_likes (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                geostory_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_geostory_likes ON geostory_likes (user_id, geostory_id)');
        $this->addSql('CREATE INDEX idx_geostory_likes_geostory ON geostory_likes (geostory_id)');
        $this->addSql('CREATE INDEX idx_geostory_likes_user ON geostory_likes (user_id)');

        $this->addSql("COMMENT ON COLUMN geostory_likes.created_at IS '(DC2Type:datetimetz_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE geostory_likes');
    }
}
