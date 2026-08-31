<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260822130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'follows: user_follows (usuario sigue a negocio o influencer)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_follows (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                target_type VARCHAR(20) NOT NULL,
                target_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        // Un usuario sólo puede seguir una vez a cada destino.
        $this->addSql('CREATE UNIQUE INDEX uniq_user_follows ON user_follows (user_id, target_type, target_id)');
        // Contar seguidores de un negocio/influencer.
        $this->addSql('CREATE INDEX idx_user_follows_target ON user_follows (target_type, target_id)');
        // Listar lo que sigue un usuario.
        $this->addSql('CREATE INDEX idx_user_follows_user ON user_follows (user_id)');

        $this->addSql("COMMENT ON COLUMN user_follows.created_at IS '(DC2Type:datetimetz_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_follows');
    }
}
