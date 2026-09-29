<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Categorías que sólo asigna el equipo desde el panel (`categories.admin_only`).
 *
 * «Restaurantes Top» es un reconocimiento, no una categoría que el negocio
 * elija: si saliera en el alta, cualquier restaurante se pondría ahí. Se sigue
 * enseñando al público como cualquier otra.
 */
final class Version20260929100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'categories.admin_only: las que no se eligen en el alta ni en la edición del negocio (Restaurantes Top)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categories ADD admin_only BOOLEAN DEFAULT false NOT NULL');
        $this->addSql("UPDATE categories SET admin_only = true WHERE slug = 'restaurants-top'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categories DROP admin_only');
    }
}
