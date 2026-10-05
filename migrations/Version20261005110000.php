<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los subniveles de Eventos, con nombre en español en vez de clave.
 *
 * Hasta ahora no se enseñaban fuera del panel, que los traducía por su cuenta.
 * Desde el 05-10-2026 la web y la app los ofrecen como segundo filtro, y con el
 * nombre ya escrito los pinta cualquier cliente aunque no tenga la traducción
 * —igual que Niños, Cine o Circo—. Los clientes nuevos traducen por el slug
 * (`category.<slug>`) para el inglés.
 */
final class Version20261005110000 extends AbstractMigration
{
    private const NAMES = [
        'events-nightlife-clubs'          => 'Discotecas',
        'events-nightlife-dj-sessions'    => 'Sesiones DJ',
        'events-nightlife-electronic'     => 'Electrónica',
        'events-nightlife-tardeo'         => 'Tardeo',
        'events-nightlife-theme-parties'  => 'Fiestas temáticas',
        'events-stage-theater'            => 'Teatro',
        'events-stage-musicals'           => 'Musicales',
        'events-stage-comedy'             => 'Humor y monólogos',
        'events-stage-dance'              => 'Danza',
        'events-stage-magic'              => 'Magia',
        'events-stage-microtheater'       => 'Microteatro',
        'events-flamenco-tablao'          => 'Tablao',
        'events-flamenco-show'            => 'Espectáculo flamenco',
        'events-flamenco-venue'           => 'Flamenco en sala',
        'events-art-museums'              => 'Museos',
        'events-art-temporary'            => 'Exposiciones temporales',
        'events-art-immersive'            => 'Inmersivas',
        'events-art-galleries'            => 'Galerías',
        'events-art-photography'          => 'Fotografía',
        'events-markets-flea'             => 'Mercadillos',
        'events-markets-vintage-crafts'   => 'Vintage y artesanía',
        'events-markets-food'             => 'Gastromercados',
        'events-markets-fairs'            => 'Ferias',
        'events-festivities-neighborhood' => 'Fiestas de barrio',
        'events-festivities-christmas'    => 'Navidad',
        'events-festivities-san-isidro'   => 'San Isidro',
        'events-festivities-hispanidad'   => 'Hispanidad',
        'events-festivities-carnival'     => 'Carnaval',
        'events-experiences-workshops'    => 'Talleres',
        'events-experiences-guided-tours' => 'Visitas guiadas',
        'events-experiences-gastronomy'   => 'Gastronomía',
        'events-experiences-sport'        => 'Deporte',
    ];

    public function getDescription(): string
    {
        return 'Subniveles de Eventos con nombre en español (se ofrecen como filtro)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::NAMES as $slug => $name) {
            $this->addSql('UPDATE categories SET name = ?, updated_at = NOW() WHERE slug = ?', [$name, $slug]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_keys(self::NAMES) as $slug) {
            $this->addSql('UPDATE categories SET name = ? WHERE slug = ?', ['category.' . $slug, $slug]);
        }
    }
}
