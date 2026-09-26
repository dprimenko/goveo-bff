<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Segunda vuelta a los grupos, con el Excel final de negocio (26-09-2026,
 * `CATEGORIA APP Final sept 26`).
 *
 * - **Ocio**, grupo nuevo de Consumo local, segundo tras Gastronomía: Vida
 *   Nocturna (sale de Gastronomía), Música en directo y Cultura y espectáculos
 *   (nuevas) y Experiencias (viene de Turismo y se queda con Workshops: escape
 *   rooms, bolera, karting… son ocio de aquí, no turismo).
 * - Turismo pierde su grupo de Experiencias y se queda en cuatro, con los
 *   nombres tal cual los quiere negocio («Cultura turismo», «Gastro Turismo»,
 *   «Compras Turismo»).
 * - «Cultura y Regalos» pasa a «Regalos».
 * - Lo que quedó por clasificar en Turismo · Cultura eran teatros y un tablao:
 *   van a Ocio. Sólo si siguen ahí — negocio está reclasificando a mano desde
 *   el panel y no se le pisa lo que ya haya movido.
 *
 * Ocio lleva de momento el icono de Experiencias, que queda libre.
 *
 * Irreversible, como la anterior: se fusiona Workshops y se mueven negocios.
 */
final class Version20260926130000 extends AbstractMigration
{
    /** Consumo local, en el orden del Excel. */
    private const LOCAL_ORDER = [
        'gastronomy', 'leisure', 'fashion-style', 'beauty-wellness',
        'culture-gifts', 'family-pets', 'home', 'tech-services',
    ];

    private const TOURISM_ORDER = [
        'tourism-culture', 'tourism-gastronomy', 'tourism-shopping', 'tourism-accommodation',
    ];

    /** Subcategorías de Ocio, en orden: las dos primeras nuevas no, se crean aparte. */
    private const LEISURE = ['nightlife', 'live-music', 'culture-shows', 'experiences'];

    /** Nombres provisionales en español (ver Version20260926120000::NAMES). */
    private const NAMES = [
        'leisure'            => 'Ocio',
        'live-music'         => 'Música en directo',
        'culture-shows'      => 'Cultura y espectáculos',
        'culture-gifts'      => 'Regalos',
        'tourism-culture'    => 'Cultura turismo',
        'tourism-gastronomy' => 'Gastro Turismo',
        'tourism-shopping'   => 'Compras Turismo',
    ];

    public function getDescription(): string
    {
        return 'Grupos (Excel final): Ocio en Consumo local, fuera Experiencias de Turismo, Regalos, nombres de Turismo';
    }

    public function up(Schema $schema): void
    {
        $retail = '523d6611-8d0c-5241-961f-4e3ec74b129a';

        // Ocio y sus dos subcategorías nuevas.
        $this->insert('leisure', null, 'local');
        $this->insert('live-music', 'leisure', null);
        $this->insert('culture-shows', 'leisure', null);

        foreach (self::LEISURE as $i => $slug) {
            $this->addSql(
                'UPDATE categories SET parent_id = (SELECT id FROM categories WHERE slug = ?), "order" = ?, updated_at = NOW()
                  WHERE slug = ?',
                [ 'leisure', $i + 1, $slug],
            );
        }

        // Workshops (Turismo) se funde en Experiencias; el grupo de Turismo de
        // Experiencias se va y lo que colgara de él pasa también ahí.
        foreach (['tourism-workshops', 'tourism-experiences'] as $from) {
            foreach (['business', 'products', 'geostories'] as $table) {
                $this->addSql(
                    "UPDATE {$table} SET category_id = (SELECT id FROM categories WHERE slug = 'experiences')
                      WHERE category_id = (SELECT id FROM categories WHERE slug = ?)",
                    [$from],
                );
            }
            $this->addSql(
                'UPDATE categories SET deleted_at = NOW(), updated_at = NOW() WHERE slug = ? AND deleted_at IS NULL',
                [$from],
            );
        }

        // Teatros y tablao que siguen por clasificar en Turismo · Cultura.
        foreach ([['culture-shows', "unaccent(lower(b.name)) NOT LIKE '%moreria%'"],
                  ['live-music',    "unaccent(lower(b.name)) LIKE '%moreria%'"]] as [$to, $match]) {
            foreach (['business', 'products'] as $table) {
                $idColumn = $table === 'business' ? 'id' : 'business_id';
                $this->addSql(
                    "UPDATE {$table} SET category_id = (SELECT id FROM categories WHERE slug = ?)
                      WHERE {$idColumn} IN (
                          SELECT b.id FROM business b
                           WHERE b.category_id = (SELECT id FROM categories WHERE slug = 'tourism-culture')
                             AND {$match})",
                    [$to],
                );
            }
        }

        // Orden de los grupos.
        foreach ([self::LOCAL_ORDER, self::TOURISM_ORDER] as $order) {
            foreach ($order as $i => $slug) {
                $this->addSql('UPDATE categories SET "order" = ? WHERE slug = ?', [$i + 1, $slug]);
            }
        }

        foreach (self::NAMES as $slug => $name) {
            $this->addSql('UPDATE categories SET name = ?, updated_at = NOW() WHERE slug = ?', [$name, $slug]);
        }

        // Ocio, con el icono de Experiencias mientras no haya uno suyo.
        $this->addSql(
            'UPDATE categories SET image = ? WHERE slug = ?',
            [sprintf(Version20260926120000::ICON_URL, Version20260926120000::idFor('tourism-experiences')), 'leisure'],
        );

        // Tipo retail en lo nuevo, como el resto de Consumo local.
        $this->addSql(
            "INSERT INTO categories_category_types (category_id, type_id)
             SELECT c.id, ? FROM categories c
              WHERE c.slug IN ('leisure', 'live-music', 'culture-shows', 'experiences')
                AND NOT EXISTS (SELECT 1 FROM categories_category_types t WHERE t.category_id = c.id AND t.type_id = ?)",
            [$retail, $retail],
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Workshops se funde en Experiencias y se mueven negocios.');
    }

    private function insert(string $slug, ?string $parent, ?string $section): void
    {
        $this->addSql(
            "INSERT INTO categories (id, name, slug, \"order\", mode, parent_id, section, created_at, updated_at)
             SELECT ?::uuid, ?, ?, 0, 'business', (SELECT id FROM categories WHERE slug = ?), ?, NOW(), NOW()
              WHERE NOT EXISTS (SELECT 1 FROM categories WHERE slug = ?)",
            [Version20260926120000::idFor($slug), 'category.' . $slug, $slug, $parent, $section, $slug],
        );
    }
}
