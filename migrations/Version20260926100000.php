<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El subnivel de los tipos de evento (Noche y fiesta → Discotecas, Sesiones DJ…)
 * y `geostories.subtype_id` para guardarlo.
 *
 * **No es un filtro**: en la app y la web se filtra sólo por el tipo, para no
 * abrumar. El subnivel se guarda para que los datos estén completos el día que
 * haga falta, y lo rellena sobre todo el scraping. Un evento sin subnivel se
 * queda con su tipo y ya está.
 *
 * Va en una columna aparte y no sustituyendo al tipo, así el filtro, la app y la
 * web publicadas no cambian: siguen mirando `subcategory_id`.
 *
 * Conciertos pequeños y Otros no tienen subnivel.
 */
final class Version20260926100000 extends AbstractMigration
{
    /** tipo => [slug del subnivel => orden]. El nombre es `category.<slug>`. */
    private const SUBTYPES = [
        'events-nightlife' => [
            'events-nightlife-clubs', 'events-nightlife-dj-sessions', 'events-nightlife-electronic',
            'events-nightlife-tardeo', 'events-nightlife-theme-parties',
        ],
        'events-stage' => [
            'events-stage-theater', 'events-stage-musicals', 'events-stage-comedy',
            'events-stage-dance', 'events-stage-magic', 'events-stage-microtheater',
        ],
        'events-flamenco' => [
            'events-flamenco-tablao', 'events-flamenco-show', 'events-flamenco-venue',
        ],
        'events-art' => [
            'events-art-museums', 'events-art-temporary', 'events-art-immersive',
            'events-art-galleries', 'events-art-photography',
        ],
        'events-markets' => [
            'events-markets-flea', 'events-markets-vintage-crafts', 'events-markets-food',
            'events-markets-fairs',
        ],
        'events-festivities' => [
            'events-festivities-neighborhood', 'events-festivities-christmas',
            'events-festivities-san-isidro', 'events-festivities-hispanidad',
            'events-festivities-carnival',
        ],
        'events-experiences' => [
            'events-experiences-cinema', 'events-experiences-workshops',
            'events-experiences-guided-tours', 'events-experiences-gastronomy',
            'events-experiences-sport',
        ],
    ];

    public function getDescription(): string
    {
        return 'Subnivel de los tipos de evento (categorías nietas de events) y geostories.subtype_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE geostories ADD subtype_id UUID DEFAULT NULL');

        foreach (self::SUBTYPES as $parent => $slugs) {
            foreach ($slugs as $i => $slug) {
                $this->addSql(
                    "INSERT INTO categories (id, name, slug, \"order\", mode, parent_id, created_at, updated_at)
                     SELECT gen_random_uuid(), ?, ?, ?, 'both', p.id, NOW(), NOW()
                       FROM categories p
                      WHERE p.slug = ?
                        AND NOT EXISTS (SELECT 1 FROM categories WHERE slug = ?)",
                    ['category.' . $slug, $slug, $i + 1, $parent, $slug],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE geostories DROP subtype_id');
        $slugs = array_merge(...array_values(self::SUBTYPES));
        $this->addSql(
            'DELETE FROM categories WHERE slug IN (' . implode(', ', array_fill(0, count($slugs), '?')) . ')',
            $slugs,
        );
    }
}
