<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los eventos importados se quedan con sus dos fechas escritas.
 *
 * Llegaron de Firestore sin `ended_at` —y un par de ellos ni siquiera con
 * `started_at`—, y la vigencia se decide con las dos: un evento se ve hasta que
 * termina. Sin fin, el feed tenía que suponerlo en cada consulta, y sin inicio
 * no había nada con lo que caducar, así que esos dos se quedaban a la vista
 * para siempre pese a haber terminado hace años.
 *
 * Se rellenan con la misma regla que aplica el alta (ver `StorySchedule`): tres
 * horas desde el inicio, y para los que no lo tienen, el momento en que se
 * subieron. Con esto la consulta del feed compara contra columnas y no contra
 * cuentas hechas al vuelo.
 */
final class Version20260903170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'geostories: fechas de inicio y fin en los eventos importados';
    }

    public function up(Schema $schema): void
    {
        $events = "SELECT id FROM categories WHERE slug = 'events'";

        // Sin inicio: el momento en que se subió. Ya pasó, que es lo que
        // corresponde — son eventos terminados hace tiempo.
        $this->addSql("
            UPDATE geostories
            SET started_at = created_at
            WHERE started_at IS NULL
              AND category_id IN ({$events})
        ");

        $this->addSql("
            UPDATE geostories
            SET ended_at = started_at + INTERVAL '3 hours'
            WHERE ended_at IS NULL
              AND started_at IS NOT NULL
              AND category_id IN ({$events})
        ");
    }

    public function down(Schema $schema): void
    {
        // No se deshace: no queda registro de cuáles eran nulos antes, y
        // vaciarlos todos borraría también las fechas que puso su dueño a mano.
        $this->throwIrreversibleMigrationException();
    }
}
