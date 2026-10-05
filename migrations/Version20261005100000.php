<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «Conciertos pequeños» pasa a **Conciertos**, con dos subniveles: **Música
 * moderna** y **Música clásica**. Lo pidió negocio: la clásica se perdía, una
 * parte dentro de Escena (el Auditorio Nacional) y otra mezclada con las salas.
 *
 * - El slug no cambia (`events-small-concerts`): es por lo que filtran la app
 *   y la web, y cambiarlo rompería la app publicada.
 * - El nombre va en español y no como clave, como Niños: la app publicada
 *   traduce la clave a «Conciertos pequeños» y seguiría diciendo eso.
 * - Lo ya importado se reparte con la regla de `ConcertKind` (aquí en SQL), y
 *   el Auditorio Nacional sale de Escena.
 */
final class Version20261005100000 extends AbstractMigration
{
    private const CONCERTS  = 'events-small-concerts';
    private const MODERN    = 'events-small-concerts-modern';
    private const CLASSICAL = 'events-small-concerts-classical';

    /** Las señas de la clásica de `ConcertKind`, para lo que ya está en la base. */
    private const CLASSICAL_SIGNS = '(orquesta|sinf[oó]nic|filarm[oó]nic|c[aá]mara|cuarteto de cuerda|coral|coro|[oó]pera|'
        . 'zarzuela|l[ií]ric|barroc|cl[aá]sic|recital|[oó]rgano|bach|mozart|beethoven|vivaldi|brahms|schubert|chopin|'
        . 'haydn|mahler|tchaikovsk|chaikovsk|debussy|ravel|ocne|scherzo|ibermúsica|juventudes musicales)';

    public function getDescription(): string
    {
        return '«Conciertos» con subniveles Música moderna y Música clásica; lo ya importado, repartido';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE categories SET name = 'Conciertos', updated_at = NOW() WHERE slug = ?", [self::CONCERTS]);

        foreach ([self::MODERN => ['Música moderna', 1], self::CLASSICAL => ['Música clásica', 2]] as $slug => [$name, $order]) {
            $this->addSql(
                "INSERT INTO categories (id, name, slug, \"order\", mode, parent_id, created_at, updated_at)
                 SELECT gen_random_uuid(), ?, ?, ?, 'both', p.id, NOW(), NOW()
                   FROM categories p
                  WHERE p.slug = ?
                    AND NOT EXISTS (SELECT 1 FROM categories WHERE slug = ?)",
                [$name, $slug, $order, self::CONCERTS, $slug],
            );
        }

        // El Auditorio Nacional iba a Escena sin subnivel.
        $this->addSql(
            "UPDATE geostories
                SET subcategory_id = (SELECT id FROM categories WHERE slug = ?),
                    subtype_id     = (SELECT id FROM categories WHERE slug = ?),
                    updated_at     = NOW()
              WHERE external_ref LIKE 'auditorio-nacional:%'
                AND subcategory_id = (SELECT id FROM categories WHERE slug = 'events-stage')",
            [self::CONCERTS, self::CLASSICAL],
        );

        // Los conciertos sin subnivel: clásica por sus señas, moderna el resto.
        $this->addSql(
            "UPDATE geostories
                SET subtype_id = (SELECT id FROM categories WHERE slug = CASE
                                    WHEN title ~* ? THEN ? ELSE ? END),
                    updated_at = NOW()
              WHERE subcategory_id = (SELECT id FROM categories WHERE slug = ?)
                AND subtype_id IS NULL",
            [self::CLASSICAL_SIGNS, self::CLASSICAL, self::MODERN, self::CONCERTS],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'UPDATE geostories SET subtype_id = NULL
              WHERE subtype_id IN (SELECT id FROM categories WHERE slug IN (?, ?))',
            [self::MODERN, self::CLASSICAL],
        );
        $this->addSql('DELETE FROM categories WHERE slug IN (?, ?)', [self::MODERN, self::CLASSICAL]);
        $this->addSql("UPDATE categories SET name = 'category.events-small-concerts' WHERE slug = ?", [self::CONCERTS]);
    }
}
