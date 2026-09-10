<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Una sola puerta para los vídeos: `verified_at`.
 *
 * Había dos, y ni siquiera filtraban igual. `published_at` se exigía **siempre**,
 * incluso al dueño mirando su propio perfil; `verified_at`, sólo para el resto
 * del mundo. En teoría eran cosas distintas —publicado por su dueño frente a
 * revisado por nosotros—, pero la app no tiene borradores: al subir un vídeo se
 * publica en el acto, así que `published_at` no distinguía nada. Lo único que
 * decidía de verdad era `verified_at`.
 *
 * Con dos columnas para lo mismo, la de más sólo servía para esconder vídeos sin
 * motivo: **43 no la tenían** —importados, o de antes de que existiera—, y ésos
 * no los veía nadie, ni su dueño. Veinte de ellos estaban revisados y aprobados.
 *
 * Al quitarla, esos veinte pasan a verse, que es lo que debía haber pasado
 * cuando se aprobaron.
 */
final class Version20260910090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'geostories: fuera published_at, la visibilidad la decide verified_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_geostories_active');

        // El índice que sustituye al anterior: la consulta del feed filtra por
        // borrado y verificado, y ordena por fecha.
        $this->addSql('
            CREATE INDEX idx_geostories_visible
                ON geostories (started_at, created_at)
             WHERE deleted_at IS NULL AND verified_at IS NOT NULL
        ');

        $this->addSql('ALTER TABLE geostories DROP COLUMN published_at');
    }

    public function down(Schema $schema): void
    {
        // La columna se puede recrear; sus valores no. Se rellena con la fecha
        // de creación, que es lo que traían todos menos los 43 que la tenían
        // vacía —y ésos no hay forma de distinguirlos después.
        $this->addSql('ALTER TABLE geostories ADD COLUMN published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE geostories SET published_at = created_at');
        $this->addSql('DROP INDEX IF EXISTS idx_geostories_visible');
        $this->addSql('
            CREATE INDEX idx_geostories_active
                ON geostories (published_at, started_at, created_at)
             WHERE deleted_at IS NULL AND published_at IS NOT NULL
        ');
    }
}
