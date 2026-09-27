<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moderación del contenido de usuarios, por la guideline 1.2 de Apple (la
 * revisión de la 1.6.0 la rechazó por no tenerla):
 *
 * - `content_reports`: denuncias de vídeos, productos y cuentas.
 * - `user_blocks`: cuentas que un usuario no quiere volver a ver.
 * - `terms_acceptances`: qué versión de las condiciones aceptó cada usuario.
 */
final class Version20260927100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Moderación: denuncias, bloqueos y aceptación de condiciones';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE content_reports (
                id          UUID NOT NULL,
                user_id     UUID NOT NULL,
                target_type VARCHAR(20) NOT NULL,
                target_id   UUID NOT NULL,
                owner_type  VARCHAR(20) DEFAULT NULL,
                owner_id    UUID DEFAULT NULL,
                reason      VARCHAR(20) NOT NULL,
                comment     TEXT DEFAULT NULL,
                status      VARCHAR(20) NOT NULL,
                created_at  TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                resolved_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                resolved_by VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_content_reports_status ON content_reports (status, created_at)');
        $this->addSql('CREATE INDEX idx_content_reports_target ON content_reports (target_type, target_id)');
        $this->addSql('CREATE INDEX idx_content_reports_user ON content_reports (user_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE user_blocks (
                id          UUID NOT NULL,
                user_id     UUID NOT NULL,
                target_type VARCHAR(20) NOT NULL,
                target_id   UUID NOT NULL,
                created_at  TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_user_blocks ON user_blocks (user_id, target_type, target_id)');
        $this->addSql('CREATE INDEX idx_user_blocks_user ON user_blocks (user_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE terms_acceptances (
                id          UUID NOT NULL,
                user_id     VARCHAR(255) NOT NULL,
                version     VARCHAR(32) NOT NULL,
                accepted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_terms_acceptances ON terms_acceptances (user_id, version)');

        foreach (['content_reports.created_at', 'content_reports.resolved_at',
                  'user_blocks.created_at', 'terms_acceptances.accepted_at'] as $column) {
            $this->addSql("COMMENT ON COLUMN {$column} IS '(DC2Type:datetimetz_immutable)'");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE terms_acceptances');
        $this->addSql('DROP TABLE user_blocks');
        $this->addSql('DROP TABLE content_reports');
    }
}
