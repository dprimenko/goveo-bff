<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'guardados: saved_geostories (vídeos que guarda cada usuario)';
    }

    public function up(Schema $schema): void
    {
        // La pareja usuario + vídeo es la clave: guardar dos veces no duplica.
        $this->addSql(<<<'SQL'
            CREATE TABLE saved_geostories (
                user_id UUID NOT NULL,
                geostory_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY(user_id, geostory_id)
            )
        SQL);

        // Listar los de un usuario, el último guardado primero.
        $this->addSql('CREATE INDEX idx_saved_geostories_user ON saved_geostories (user_id, created_at)');
        // Limpiar al borrar un vídeo.
        $this->addSql('CREATE INDEX idx_saved_geostories_geostory ON saved_geostories (geostory_id)');

        $this->addSql("COMMENT ON COLUMN saved_geostories.created_at IS '(DC2Type:datetimetz_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE saved_geostories');
    }
}
