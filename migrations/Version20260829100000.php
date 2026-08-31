<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tokens para que el dueño de un negocio se ponga su contraseña.
 *
 * El alta web crea la cuenta sin contraseña, así que hace falta una forma de
 * que la defina desde el correo de bienvenida.
 *
 * Se guarda el **hash** del token, no el token: si alguien lee la tabla no puede
 * entrar en ninguna cuenta. Mismo criterio que con una contraseña.
 */
final class Version20260829100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'account: tokens de un solo uso para crear la contraseña inicial';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE password_setup_tokens (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_password_setup_token ON password_setup_tokens (token_hash)');
        $this->addSql('CREATE INDEX IDX_password_setup_user ON password_setup_tokens (user_id)');

        foreach (['expires_at', 'used_at', 'created_at'] as $column) {
            $this->addSql("COMMENT ON COLUMN password_setup_tokens.$column IS '(DC2Type:datetimetz_immutable)'");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE password_setup_tokens');
    }
}
