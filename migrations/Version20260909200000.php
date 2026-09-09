<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un negocio puede quedar **rechazado**, y no sólo «sin validar».
 *
 * Hasta ahora la validación era una sola fecha: con `verified_at` el negocio se
 * ve, sin ella no. Eso basta para el feed, pero deja la cola de revisión del
 * backoffice sin fondo: los que alguien miró y descartó vuelven a salir como
 * pendientes en cada visita, mezclados con los que nadie ha tocado, y no hay
 * forma de saber cuáles son cuáles.
 *
 * `rejected_at` separa las dos cosas. Pendiente es no tener ninguna de las dos
 * fechas; rechazado es tener ésta. Para lo público no cambia nada —lo que se
 * mira sigue siendo `verified_at`—, así que un rechazado se comporta igual que
 * un pendiente de cara al feed, el mapa y la búsqueda.
 *
 * Se puede volver atrás: aprobar limpia el rechazo, porque el error de quien
 * revisa tiene que poder deshacerse sin tocar la base a mano.
 */
final class Version20260909200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'business: rejected_at para la cola de revisión del backoffice';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business ADD COLUMN rejected_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');

        // La cola del panel filtra por «ninguna de las dos fechas» sobre los no
        // borrados. Sin índice es un scan de toda la tabla en cada carga.
        $this->addSql('
            CREATE INDEX idx_business_pending_review
                ON business (created_at DESC)
             WHERE deleted_at IS NULL AND verified_at IS NULL AND rejected_at IS NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_business_pending_review');
        $this->addSql('ALTER TABLE business DROP COLUMN rejected_at');
    }
}
