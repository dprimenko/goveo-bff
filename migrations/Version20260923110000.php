<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fuera «Eventos» de negocio (`eventss`): lo que tenía pasa a «Cultura».
 *
 * Era un duplicado de `events` —la de influencer— con la misma traducción, y lo
 * que había dentro eran sobre todo teatros, que es justo lo que recoge
 * `culture-business` (ver `Version20260923100000`). Se mueven los negocios y,
 * con ellos, sus productos y vídeos: si no, quedarían apuntando a una categoría
 * que ya no sale en ningún sitio.
 *
 * **Borrado lógico**, no `DELETE`: la fila sigue ahí por si hay que volver atrás
 * o algo antiguo la sigue nombrando, pero `/public/categories` ya no la devuelve
 * (filtra `deleted_at IS NULL`), así que desaparece de los círculos y de los
 * desplegables.
 *
 * Los negocios que están en la `culture` de influencer **no** se tocan aquí.
 */
final class Version20260923110000 extends AbstractMigration
{
    private const CULTURE_BUSINESS = '7ec3fb98-daa0-5ce0-bdde-f3b3f97bdbc6';

    public function getDescription(): string
    {
        return 'categories: borrado lógico de eventss; sus negocios pasan a culture-business';
    }

    public function up(Schema $schema): void
    {
        $eventss = "(SELECT id FROM categories WHERE slug = 'eventss')";

        foreach (['business', 'products', 'geostories'] as $table) {
            $this->addSql(sprintf(
                "UPDATE %s SET category_id = '%s', updated_at = NOW() WHERE category_id = %s",
                $table,
                self::CULTURE_BUSINESS,
                $eventss,
            ));
        }

        $this->addSql("
            UPDATE categories
            SET deleted_at = NOW(), updated_at = NOW()
            WHERE slug = 'eventss' AND deleted_at IS NULL
        ");
    }

    public function down(Schema $schema): void
    {
        // Sólo se recupera la categoría: no queda registro de qué negocios
        // estaban en ella, y devolverlos habría que hacerlo a mano.
        $this->addSql("UPDATE categories SET deleted_at = NULL, updated_at = NOW() WHERE slug = 'eventss'");
    }
}
