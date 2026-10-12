<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tipos y subniveles de evento para la cartelera de **El Corte Inglés** (feed de
 * Awin, `goveo:events:import-awin`), que trae cosas que no teníamos dónde poner.
 * Decidido por el usuario el 12-10-2026:
 *
 * - **Deporte** (tipo): Fútbol, Motor, Deportes de contacto, Carreras populares y
 *   Tours de estadio. El subnivel Planes › Deporte se queda para lo de practicar.
 * - **Charlas y conferencias** (tipo): conferencias, seminarios, podcast en vivo.
 * - Conciertos › **Festivales** y › **Grandes conciertos** (estadios, pabellones):
 *   «Conciertos grandes» no entraba hasta ahora.
 * - Niños › **Parques y zoos**: Bioparc, acuarios, Micropolix… que antes se
 *   dejaban fuera por no tener actividad con fecha.
 * - Planes › **Espectáculos ecuestres** y › **Escape rooms**.
 *
 * Nombres en español y no clave, como Niños y Cine: la app publicada no tiene
 * esas traducciones y pintaría el slug. Los tipos van antes de «Otros» (99); el
 * orden lo cambia luego el panel.
 */
final class Version20261012100000 extends AbstractMigration
{
    /** slug => [nombre, orden] */
    private const TYPES = [
        'events-sport' => ['Deporte', 20],
        'events-talks' => ['Charlas y conferencias', 21],
    ];

    /** slug => [nombre, orden, tipo] */
    private const SUBTYPES = [
        'events-sport-football'           => ['Fútbol', 1, 'events-sport'],
        'events-sport-motor'              => ['Motor', 2, 'events-sport'],
        'events-sport-combat'             => ['Deportes de contacto', 3, 'events-sport'],
        'events-sport-races'              => ['Carreras populares', 4, 'events-sport'],
        'events-sport-stadium-tours'      => ['Tours de estadio', 5, 'events-sport'],
        'events-small-concerts-festivals' => ['Festivales', 3, 'events-small-concerts'],
        'events-small-concerts-big'       => ['Grandes conciertos', 4, 'events-small-concerts'],
        'events-kids-parks'               => ['Parques y zoos', 5, 'events-kids'],
        'events-experiences-equestrian'   => ['Espectáculos ecuestres', 5, 'events-experiences'],
        'events-experiences-escape'       => ['Escape rooms', 6, 'events-experiences'],
    ];

    public function getDescription(): string
    {
        return 'Eventos: tipos Deporte y Charlas y conferencias, y subniveles para la cartelera de El Corte Inglés';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TYPES as $slug => [$name, $order]) {
            $this->insert($slug, $name, $order, 'events');
        }
        foreach (self::SUBTYPES as $slug => [$name, $order, $parent]) {
            $this->insert($slug, $name, $order, $parent);
        }
    }

    public function down(Schema $schema): void
    {
        $slugs = [...array_keys(self::SUBTYPES), ...array_keys(self::TYPES)];
        $in    = implode(', ', array_fill(0, count($slugs), '?'));

        $this->addSql("UPDATE geostories SET subtype_id = NULL WHERE subtype_id IN (SELECT id FROM categories WHERE slug IN ({$in}))", $slugs);
        $this->addSql(
            "UPDATE geostories SET subcategory_id = (SELECT id FROM categories WHERE slug = 'events-other')
              WHERE subcategory_id IN (SELECT id FROM categories WHERE slug IN ({$in}))",
            $slugs,
        );
        $this->addSql("DELETE FROM categories WHERE slug IN ({$in})", $slugs);
    }

    private function insert(string $slug, string $name, int $order, string $parent): void
    {
        $this->addSql(
            "INSERT INTO categories (id, name, slug, \"order\", mode, parent_id, created_at, updated_at)
             SELECT gen_random_uuid(), ?, ?, ?, 'both', p.id, NOW(), NOW()
               FROM categories p
              WHERE p.slug = ? AND p.deleted_at IS NULL
                AND NOT EXISTS (SELECT 1 FROM categories WHERE slug = ?)",
            [$name, $slug, $order, $parent, $slug],
        );
    }
}
