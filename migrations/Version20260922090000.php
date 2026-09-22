<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `geostories.media_type`: una geostory puede ser una foto.
 *
 * Hasta ahora todo lo que se publicaba era vídeo, y el resto del sistema lo
 * daba por hecho: se sube a Bunny Stream, nace en `processing` y espera al aviso
 * de que ha terminado de codificar. Una foto no hace nada de eso —se guarda en
 * el almacenamiento de imágenes y ya se puede ver—, así que la tarjeta, el
 * panel y el propio alta necesitan saber cuál de las dos cosas están mirando.
 *
 * **En columna y no en `meta`** porque decide qué se pinta: `meta` es donde
 * viven los datos que no son de nuestro dominio (el enlace externo, sin ir más
 * lejos), y esto sí lo es.
 *
 * Todo lo que ya existe es vídeo, que es justo lo que dice el valor por
 * defecto: la columna nace rellena y ninguna consulta anterior cambia de
 * resultado.
 */
final class Version20260922090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'geostories.media_type (video|image), por defecto video';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE geostories
                ADD COLUMN IF NOT EXISTS media_type VARCHAR(10) NOT NULL DEFAULT 'video'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE geostories DROP COLUMN IF EXISTS media_type');
    }
}
