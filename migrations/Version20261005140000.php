<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Teatro y escena**, un solo tipo con el teatro y el circo dentro (05-10-2026).
 *
 * Deshace el reparto en tres tipos de `Version20261005120000` y el tipo Circo
 * de `Version20261004130000`: negocio lo quiere todo en un tipo, con el sitio
 * como subnivel más. Subniveles, en este orden: Teatro en grandes salas, Teatro
 * en salas, Teatro en centros culturales, Musicales, Humor y monólogos, Danza,
 * Magia, Microteatro y Circo.
 *
 * - Lo de Grandes teatros y Salas vuelve a `events-stage` con el subnivel de su
 *   sala; lo de Circo, con el de Circo; lo que estaba en Escena sin subnivel,
 *   a centros culturales (es lo que era).
 * - Grandes teatros, Salas y Circo se borran en blando: lo que una app o la web
 *   tuviera elegido deja de existir y vuelve a «Todos».
 * - Los tipos se reordenan como pidió negocio.
 */
final class Version20261005140000 extends AbstractMigration
{
    /** Subniveles nuevos de Teatro y escena => nombre. */
    private const NEW_SUBTYPES = [
        'events-stage-big-venues' => 'Teatro en grandes salas',
        'events-stage-halls'      => 'Teatro en salas',
        'events-stage-cultural'   => 'Teatro en centros culturales',
        'events-stage-circus'     => 'Circo',
    ];

    /** Subniveles de Teatro y escena, en orden. */
    private const STAGE_ORDER = [
        'events-stage-big-venues', 'events-stage-halls', 'events-stage-cultural', 'events-stage-musicals',
        'events-stage-comedy', 'events-stage-dance', 'events-stage-magic', 'events-stage-microtheater',
        'events-stage-circus',
    ];

    /** Los tipos, en orden. */
    private const TYPE_ORDER = [
        'events-small-concerts', 'events-nightlife', 'events-kids', 'events-stage', 'events-flamenco',
        'events-cinema', 'events-art', 'events-experiences', 'events-markets', 'events-festivities',
    ];

    public function getDescription(): string
    {
        return 'Teatro y escena: un solo tipo, con la sala y el circo como subniveles';
    }

    public function up(Schema $schema): void
    {
        foreach (self::NEW_SUBTYPES as $slug => $name) {
            $this->addSql(
                "INSERT INTO categories (id, name, slug, \"order\", mode, parent_id, created_at, updated_at)
                 SELECT gen_random_uuid(), ?, ?, 0, 'both', p.id, NOW(), NOW()
                   FROM categories p
                  WHERE p.slug = 'events-stage'
                    AND NOT EXISTS (SELECT 1 FROM categories WHERE slug = ?)",
                [$name, $slug, $slug],
            );
        }
        $this->addSql("UPDATE categories SET name = 'Teatro y escena', updated_at = NOW() WHERE slug = 'events-stage'");

        $id    = fn (string $slug): string => "(SELECT id FROM categories WHERE slug = '{$slug}')";
        $stage = $id('events-stage');

        // De los tres tipos y Circo, de vuelta a Teatro y escena.
        foreach ([
            'events-big-theaters'  => 'events-stage-big-venues',
            'events-theater-halls' => 'events-stage-halls',
            'events-circus'        => 'events-stage-circus',
        ] as $from => $subtype) {
            $this->addSql(
                "UPDATE geostories SET subcategory_id = {$stage}, subtype_id = {$id($subtype)}, updated_at = NOW()
                  WHERE subcategory_id = {$id($from)}",
            );
            $this->addSql("UPDATE categories SET deleted_at = NOW(), updated_at = NOW() WHERE slug = ? AND deleted_at IS NULL", [$from]);
        }
        // El reparto en tres quitó el subnivel a lo de Grandes teatros: los
        // musicales lo recuperan por el título, que el género gana a la sala.
        $this->addSql(
            "UPDATE geostories SET subtype_id = {$id('events-stage-musicals')}, updated_at = NOW()
              WHERE subcategory_id = {$stage} AND title ~* 'musical'
                AND (subtype_id IS NULL OR subtype_id IN ({$id('events-stage-big-venues')}, {$id('events-stage-halls')}))",
        );
        // Lo que quedó en Escena sin subnivel era lo de centros culturales.
        $this->addSql(
            "UPDATE geostories SET subtype_id = {$id('events-stage-cultural')}, updated_at = NOW()
              WHERE subcategory_id = {$stage} AND subtype_id IS NULL",
        );

        foreach (self::STAGE_ORDER as $i => $slug) {
            $this->addSql('UPDATE categories SET "order" = ? WHERE slug = ?', [$i + 1, $slug]);
        }
        foreach (self::TYPE_ORDER as $i => $slug) {
            $this->addSql('UPDATE categories SET "order" = ? WHERE slug = ?', [$i + 1, $slug]);
        }
        $this->addSql("UPDATE categories SET \"order\" = 99 WHERE slug = 'events-other'");
    }

    public function down(Schema $schema): void
    {
        $id = fn (string $slug): string => "(SELECT id FROM categories WHERE slug = '{$slug}')";
        foreach (['events-big-theaters' => 'events-stage-big-venues', 'events-theater-halls' => 'events-stage-halls', 'events-circus' => 'events-stage-circus'] as $type => $subtype) {
            $this->addSql('UPDATE categories SET deleted_at = NULL WHERE slug = ?', [$type]);
            $this->addSql(
                "UPDATE geostories SET subcategory_id = {$id($type)}, subtype_id = NULL WHERE subtype_id = {$id($subtype)}",
            );
        }
        $this->addSql("UPDATE geostories SET subtype_id = NULL WHERE subtype_id = {$id('events-stage-cultural')}");
        $this->addSql(
            'DELETE FROM categories WHERE slug IN (' . implode(', ', array_fill(0, count(self::NEW_SUBTYPES), '?')) . ')',
            array_keys(self::NEW_SUBTYPES),
        );
        $this->addSql("UPDATE categories SET name = 'Teatros en centros culturales' WHERE slug = 'events-stage'");
    }
}
