<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tarjeta de fidelización: cinco sellos por negocio, con premio en el 3 y el 5.
 *
 * - `loyalty_programs`: los premios de cada negocio y si un admin se la ha
 *   activado a mano. Sin fila, el negocio no tiene tarjeta.
 * - `loyalty_cards`: los sellos de cada usuario en cada negocio.
 * - `loyalty_tokens`: los QR que enseña el negocio, de un solo uso. Sólo el hash.
 * - `loyalty_events`: cada sello y cada canje, con el premio tal como estaba.
 *
 * Sin claves ajenas, como `user_follows` o `geostory_likes`: el borrado
 * definitivo de un negocio las limpia en `BusinessPurger`.
 */
final class Version20260924100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tarjeta de fidelización: programas, tarjetas, QR de un solo uso e historial';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE loyalty_programs (business_id UUID NOT NULL, rewards JSON DEFAULT '{}' NOT NULL, manually_enabled BOOLEAN DEFAULT false NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(business_id))");
        $this->addSql("COMMENT ON COLUMN loyalty_programs.updated_at IS '(DC2Type:datetimetz_immutable)'");

        $this->addSql('CREATE TABLE loyalty_cards (id UUID NOT NULL, user_id UUID NOT NULL, business_id UUID NOT NULL, stamps SMALLINT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_loyalty_cards_business ON loyalty_cards (business_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_loyalty_card ON loyalty_cards (user_id, business_id)');
        $this->addSql("COMMENT ON COLUMN loyalty_cards.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN loyalty_cards.updated_at IS '(DC2Type:datetimetz_immutable)'");

        $this->addSql('CREATE TABLE loyalty_tokens (id UUID NOT NULL, business_id UUID NOT NULL, kind VARCHAR(10) NOT NULL, reward_stage SMALLINT DEFAULT NULL, token_hash VARCHAR(64) NOT NULL, created_by_user_id UUID NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, used_by_user_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_loyalty_tokens_business ON loyalty_tokens (business_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_loyalty_token ON loyalty_tokens (token_hash)');
        $this->addSql("COMMENT ON COLUMN loyalty_tokens.expires_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN loyalty_tokens.used_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN loyalty_tokens.created_at IS '(DC2Type:datetimetz_immutable)'");

        $this->addSql('CREATE TABLE loyalty_events (id UUID NOT NULL, card_id UUID NOT NULL, user_id UUID NOT NULL, business_id UUID NOT NULL, kind VARCHAR(10) NOT NULL, token_id UUID NOT NULL, reward_stage SMALLINT DEFAULT NULL, reward_label VARCHAR(80) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_loyalty_events_business ON loyalty_events (business_id, created_at)');
        $this->addSql('CREATE INDEX idx_loyalty_events_card ON loyalty_events (card_id)');
        $this->addSql("COMMENT ON COLUMN loyalty_events.created_at IS '(DC2Type:datetimetz_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE loyalty_events');
        $this->addSql('DROP TABLE loyalty_tokens');
        $this->addSql('DROP TABLE loyalty_cards');
        $this->addSql('DROP TABLE loyalty_programs');
    }
}
