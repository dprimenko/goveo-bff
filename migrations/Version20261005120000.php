<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Escena se parte en tres (05-10-2026, a petición de negocio): **Grandes
 * teatros**, **Salas de teatro** y **Teatros en centros culturales**, que es
 * lo que queda de Escena (`events-stage`, mismo slug: es por lo que filtran la
 * app y la web publicadas).
 *
 * - Grandes teatros y Salas no tienen subniveles. Centros culturales se queda
 *   con Musicales, Humor y monólogos, Danza, Magia y Microteatro; el subnivel
 *   «Teatro» se retira (era el cajón por defecto).
 * - Lo ya importado se reparte con las reglas de `TheaterKind`: por la fuente
 *   y, en las agendas generales, por el nombre de la sala dueña. Lo que no se
 *   sabe —subido a mano, o de la Agenda Goveo— se queda en centros culturales y
 *   se corrige en el panel.
 * - Se reordenan los tipos para que los tres teatros vayan juntos.
 */
final class Version20261005120000 extends AbstractMigration
{
    private const BIG      = 'events-big-theaters';
    private const HALLS    = 'events-theater-halls';
    private const CULTURAL = 'events-stage';

    private const BIG_SOURCES = [
        'gruposmedia', 'stage', 'atg', 'calderon', 'grupo-marquina', 'teatro-la-latina', 'teatro-pavon',
        'infanta-isabel', 'teatro-lara', 'teatro-real', 'teatro-zarzuela', 'teatros-canal', 'cntc', 'teatro-abadia',
    ];
    private const HALL_SOURCES = ['microteatro', 'teseo-teatro', 'corral-alcala'];

    /** Los de `TheaterKind`, en sintaxis de Postgres. */
    private const BIG_VENUES = 'teatro espa[nñ]ol|naves del espa[nñ]ol|fern[aá]n g[oó]mez|teatro real|zarzuela|'
        . 'teatros del canal|mar[ií]a guerrero|valle[- ]incl[aá]n|teatro de la comedia|teatro de la abad[ií]a|'
        . 'gran v[ií]a|teatro calder[oó]n|teatro lope de vega|coliseum|nuevo teatro alcal[aá]|teatro apolo|'
        . 'teatro rialto|teatro amaya|teatro infanta isabel|teatro la latina|teatro marquina|teatro lara|'
        . 'teatro pav[oó]n|teatro bellas artes|teatro reina victoria|teatro maravillas|teatro alc[aá]zar|'
        . 'teatro f[ií]garo|teatro cofidis|teatro edp|teatro capitol|teatro arlequ[ií]n|'
        . 'teatro muñoz seca|teatro victoria|teatro galileo|teatro pr[ií]ncipe';
    private const CULTURAL_VENUES = 'centro cultural|centro sociocultural|centro municipal|casa de (la )?cultura|'
        . 'biblioteca|junta municipal|auditorio municipal|espacio municipal|centro c[ií]vico|matadero|conde duque|'
        . 'centrocentro|quinta de los molinos';

    /** slug => orden: los tres teatros juntos, detrás de Noche y fiesta. */
    private const ORDER = [
        'events-small-concerts' => 1, 'events-nightlife' => 2, self::BIG => 3, self::HALLS => 4,
        self::CULTURAL => 5, 'events-flamenco' => 6, 'events-art' => 7, 'events-markets' => 8,
        'events-festivities' => 9, 'events-experiences' => 10, 'events-kids' => 11, 'events-cinema' => 12,
        'events-circus' => 13, 'events-other' => 99,
    ];

    public function getDescription(): string
    {
        return 'Escena se parte en Grandes teatros, Salas de teatro y Teatros en centros culturales';
    }

    public function up(Schema $schema): void
    {
        foreach ([self::BIG => 'Grandes teatros', self::HALLS => 'Salas de teatro'] as $slug => $name) {
            $this->addSql(
                "INSERT INTO categories (id, name, slug, \"order\", mode, parent_id, created_at, updated_at)
                 SELECT gen_random_uuid(), ?, ?, 0, 'both', p.id, NOW(), NOW()
                   FROM categories p
                  WHERE p.slug = 'events'
                    AND NOT EXISTS (SELECT 1 FROM categories WHERE slug = ?)",
                [$name, $slug, $slug],
            );
        }
        $this->addSql("UPDATE categories SET name = 'Teatros en centros culturales', updated_at = NOW() WHERE slug = ?", [self::CULTURAL]);
        foreach (self::ORDER as $slug => $order) {
            $this->addSql('UPDATE categories SET "order" = ? WHERE slug = ?', [$order, $slug]);
        }

        $stage = "(SELECT id FROM categories WHERE slug = 'events-stage')";
        $to    = fn (string $slug): string => "subcategory_id = (SELECT id FROM categories WHERE slug = '{$slug}'), subtype_id = NULL, updated_at = NOW()";
        $from  = fn (array $sources): string => "split_part(external_ref, ':', 1) IN ('" . implode("', '", $sources) . "')";

        // Por la fuente.
        $this->addSql("UPDATE geostories SET {$to(self::BIG)} WHERE subcategory_id = {$stage} AND {$from(self::BIG_SOURCES)}");
        $this->addSql("UPDATE geostories SET {$to(self::HALLS)} WHERE subcategory_id = {$stage} AND {$from(self::HALL_SOURCES)}");

        // Las agendas generales, por la sala dueña (lo de la Agenda Goveo no
        // tiene sala: se queda).
        $venue  = '(SELECT b.name FROM business b WHERE b.id = geostories.business_id)';
        $agenda = $from(['madrid-datos', 'esmadrid', 'comunidad-madrid']);
        $this->addSql(
            "UPDATE geostories SET {$to(self::BIG)}
              WHERE subcategory_id = {$stage} AND {$agenda} AND {$venue} ~* ?",
            [self::BIG_VENUES],
        );
        $this->addSql(
            "UPDATE geostories SET {$to(self::BIG)}
              WHERE subcategory_id = {$stage} AND {$from(['esmadrid', 'comunidad-madrid'])}
                AND {$venue} !~* ? AND {$venue} ~* '^\\s*teatro\\M'",
            [self::CULTURAL_VENUES],
        );
        $this->addSql(
            "UPDATE geostories SET {$to(self::HALLS)}
              WHERE subcategory_id = {$stage} AND {$from(['esmadrid', 'comunidad-madrid'])}
                AND {$venue} IS NOT NULL AND {$venue} !~* ?",
            [self::CULTURAL_VENUES],
        );

        // «Teatro» deja de ser subnivel.
        $this->addSql(
            "UPDATE geostories SET subtype_id = NULL, updated_at = NOW()
              WHERE subtype_id = (SELECT id FROM categories WHERE slug = 'events-stage-theater')",
        );
        $this->addSql("UPDATE categories SET deleted_at = NOW(), updated_at = NOW() WHERE slug = 'events-stage-theater' AND deleted_at IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE categories SET deleted_at = NULL WHERE slug = 'events-stage-theater'");
        $this->addSql(
            "UPDATE geostories SET subcategory_id = (SELECT id FROM categories WHERE slug = 'events-stage')
              WHERE subcategory_id IN (SELECT id FROM categories WHERE slug IN (?, ?))",
            [self::BIG, self::HALLS],
        );
        $this->addSql('DELETE FROM categories WHERE slug IN (?, ?)', [self::BIG, self::HALLS]);
        $this->addSql("UPDATE categories SET name = 'category.events-stage' WHERE slug = 'events-stage'");
    }
}
