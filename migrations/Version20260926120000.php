<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Reestructuración de categorías: **grupos** con subcategorías, por sección
 * (Comercio local / Turismo), y **badges** (Ecológico, Terraza).
 *
 * Los grupos son categorías de primer nivel con `section`; las subcategorías
 * cuelgan de ellos por `parent_id`, el mismo árbol que ya usan los tipos de
 * evento. Las categorías que ya existían **se cuelgan tal cual** de su grupo en
 * vez de recrearse: conservan id y slug, así que negocios, productos, vídeos y
 * los enlaces del alta (`?category=hostelry`) siguen valiendo sin tocarlos.
 *
 * - `active`: todo nace **visible**; desde el panel se oculta un grupo o una
 *   subcategoría que no convenga enseñar todavía. Lo vacío no hace falta
 *   ocultarlo: al público no le llega (`with_businesses`).
 * - Lo que se fusiona (`food` → `gourmet`, `eco`, `accommodation`,
 *   `culture-business`) se borra en blando y arrastra negocios, productos y
 *   vídeos: el patrón de Version20260923110000.
 * - Lo que necesita revisión a mano queda **colgado del grupo, sin
 *   subcategoría**: se sigue encontrando al filtrar por el grupo, y el panel lo
 *   lista como «por clasificar».
 * - Hostelería se queda como «Restaurantes» (el reparto por defecto que pide el
 *   documento); bares y cafeterías se mueven luego desde el panel.
 *
 * **Nombres en español, no claves de traducción** (`NAMES`), mientras sigan en
 * la calle apps que no conocen los grupos: la app pinta `t(name, {defaultValue:
 * name})`, así que un texto sin traducción sale tal cual en vez de como
 * `category.gastronomy`. Cuando la app nueva esté publicada, otra migración los
 * devuelve a `category.<slug>` — la lista es esta constante.
 *
 * **Ids fijos** (UUID v5 del slug, el espacio de nombres del import): los de
 * local, demo y producción coinciden, y con ellos la carpeta de su icono en el
 * almacenamiento. Con `gen_random_uuid()` cada entorno tendría los suyos.
 *
 * **Iconos** (`ICONS`): los del diseño, en `categories/<id>/icon-v1.png` del
 * almacenamiento de producción — donde están las demás imágenes de categoría.
 * Se suben a mano (ver «Grupos de categorías» en CLAUDE.md); hasta entonces el
 * círculo sale sin imagen. `-v1` para poder cambiarlos sin pelearse con la
 * caché del CDN.
 *
 * Y para las apps publicadas: sólo lo de Comercio local lleva el tipo
 * `retail`, que es por lo que piden los círculos de esa pestaña.
 *
 * No se pueden deshacer los movimientos de negocios: al fusionar se pierde de
 * qué categoría venía cada uno.
 */
final class Version20260926120000 extends AbstractMigration
{
    private const RETAIL_TYPE = '523d6611-8d0c-5241-961f-4e3ec74b129a';

    /**
     * slug del grupo => [sección, orden, subcategorías en orden].
     * Las subcategorías que ya existen se reutilizan; las demás se crean.
     */
    private const GROUPS = [
        'gastronomy' => ['local', 1, [
            'restaurants-top', 'hostelry', 'bars-tapas', 'cafe-brunch', 'pastry', 'nightlife', 'gourmet',
        ]],
        'fashion-style'   => ['local', 2, ['fashion', 'footwear', 'accesories', 'jewelry']],
        'beauty-wellness' => ['local', 3, ['beauty', 'health', 'sport']],
        // Sin «Eventos»: de momento es sólo categoría de vídeos.
        'culture-gifts'   => ['local', 4, ['gifts', 'crafts', 'bookshop']],
        'family-pets'     => ['local', 5, ['baby', 'toys', 'pets']],
        'home'            => ['local', 6, ['home_decoration', 'diy', 'home_appliances']],
        'tech-services'   => ['local', 7, ['technology', 'services', 'misc']],

        // Las de turismo llevan prefijo: `hotels`, `workshops` o `restaurants`
        // ya son slugs del partner ibiza, y el slug es único.
        'tourism-experiences'   => ['tourism', 1, ['experiences', 'tourism-workshops']],
        'tourism-culture'       => ['tourism', 2, ['tourism-museums', 'tourism-monuments', 'tourism-viewpoints']],
        'tourism-gastronomy'    => ['tourism', 3, ['tourism-gastro', 'tourism-gourmet']],
        'tourism-shopping'      => ['tourism', 4, ['tourism-crafts', 'tourism-souvenirs']],
        'tourism-accommodation' => ['tourism', 5, ['tourism-hotels', 'tourism-apartments', 'tourism-hostels']],
    ];

    /** El del import (`AbstractSupabaseMigrationCommand`): ids estables por slug. */
    private const UUID_NS = '7e4d3c2a-1b5f-4e8d-9a6c-0f2e1d3b5a7c';

    public const ICON_URL = 'https://goveo.b-cdn.net/categories/%s/icon-v1.png';

    /** Grupo => fichero del diseño (`iconos-goveo-completo.zip`). */
    public const ICONS = [
        'gastronomy'            => 'originales/gastronomia-local.png',
        'fashion-style'         => 'originales/moda-estilo.png',
        'beauty-wellness'       => 'nuevos/belleza-bienestar.png',
        'culture-gifts'         => 'nuevos/cultura-regalos.png',
        'family-pets'           => 'nuevos/familia-mascotas.png',
        'home'                  => 'nuevos/hogar.png',
        'tech-services'         => 'nuevos/tecnologia-servicios.png',
        'tourism-experiences'   => 'originales/experiencias-turismo.png',
        'tourism-culture'       => 'nuevos/cultura-turismo.png',
        'tourism-gastronomy'    => 'nuevos/gastro-turismo.png',
        'tourism-shopping'      => 'originales/artesania-compras-turismo.png',
        'tourism-accommodation' => 'originales/alojamientos-turismo.png',
    ];

    public static function idFor(string $slug): string
    {
        return Uuid::v5(Uuid::fromString(self::UUID_NS), $slug)->toRfc4122();
    }

    /**
     * slug => nombre en español, provisional (ver arriba). Incluye las dos
     * existentes que cambian de rótulo: la app publicada las traduciría como
     * «Hostelería» y «Gourmet».
     */
    public const NAMES = [
        'gastronomy'            => 'Gastronomía',
        'restaurants-top'       => 'Restaurantes Top',
        'hostelry'              => 'Restaurantes',
        'bars-tapas'            => 'Bares y Tapas',
        'cafe-brunch'           => 'Café y Brunch',
        'pastry'                => 'Pastelerías y Dulce',
        'gourmet'               => 'Gourmet y Alimentación',
        'fashion-style'         => 'Moda y Estilo',
        'beauty-wellness'       => 'Belleza y Bienestar',
        'culture-gifts'         => 'Cultura y Regalos',
        'family-pets'           => 'Familia y Mascotas',
        'home'                  => 'Hogar',
        'tech-services'         => 'Tecnología y Servicios',
        'tourism-experiences'   => 'Experiencias',
        'tourism-workshops'     => 'Workshops',
        'tourism-culture'       => 'Cultura',
        'tourism-museums'       => 'Museos',
        'tourism-monuments'     => 'Monumentos',
        'tourism-viewpoints'    => 'Miradores',
        'tourism-gastronomy'    => 'Gastro',
        'tourism-gastro'        => 'Gastro',
        'tourism-gourmet'       => 'Gourmet',
        'tourism-shopping'      => 'Artesanía y Compras',
        'tourism-crafts'        => 'Artesanía',
        'tourism-souvenirs'     => 'Souvenirs',
        'tourism-accommodation' => 'Alojamientos',
        'tourism-hotels'        => 'Hoteles',
        'tourism-apartments'    => 'Apartamentos',
        'tourism-hostels'       => 'Hostels',
    ];

    /** Categoría que desaparece => a dónde van sus negocios, productos y vídeos. */
    private const MERGES = [
        'food'             => 'gourmet',
        // Ecológico pasa a badge. Son tiendas de alimentación; se revisan igual.
        'eco'              => 'gourmet',
        // Campings y casas rurales: no encajan en hoteles/apartamentos/hostels
        // sin mirarlos, así que al grupo, por clasificar.
        'accommodation'    => 'tourism-accommodation',
        // Teatro Calderón (sin validar).
        'culture-business' => 'tourism-culture',
    ];

    /**
     * Badge => [emoji, orden, grupos donde se ofrece como filtro]. El nombre es
     * la clave `badge.<slug>`. Un badge sólo sale como chip en los grupos que
     * lo llevan: Terraza en Moda no significa nada.
     */
    private const BADGES = [
        'eco'     => ['🌱', 1, ['gastronomy']],
        'terrace' => ['☀️', 2, ['gastronomy']],
    ];

    public function getDescription(): string
    {
        return 'Grupos de categorías por sección (local/turismo), subcategorías con active, fusiones y badges';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categories ADD section VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE categories ADD active BOOLEAN DEFAULT TRUE NOT NULL');

        foreach (self::GROUPS as $group => [$section, $order, $children]) {
            $this->insertCategory($group, $order, null, $section);

            foreach ($children as $i => $child) {
                $this->insertCategory($child, $i + 1, $group, null);
                // Existentes o recién creadas: todas al grupo y en su orden.
                $this->addSql(
                    'UPDATE categories
                        SET parent_id = (SELECT id FROM categories WHERE slug = ?),
                            "order" = ?, updated_at = NOW()
                      WHERE slug = ?',
                    [$group, $i + 1, $child],
                );
            }
        }

        foreach (array_keys(self::ICONS) as $group) {
            $this->addSql(
                'UPDATE categories SET image = ? WHERE slug = ?',
                [sprintf(self::ICON_URL, self::idFor($group)), $group],
            );
        }

        foreach (self::NAMES as $slug => $name) {
            $this->addSql('UPDATE categories SET name = ? WHERE slug = ?', [$name, $slug]);
        }

        // Tipo retail en lo nuevo de Comercio local: sin él no salían en los
        // círculos de esa pestaña ni en el mapa (ver Version20260923100000).
        // Los de Turismo no: la app publicada los pintaría entre las tiendas.
        $this->addSql(
            "INSERT INTO categories_category_types (category_id, type_id)
             SELECT c.id, ? FROM categories c
              WHERE (c.section = 'local' OR c.parent_id IN (SELECT id FROM categories WHERE section = 'local'))
                AND NOT EXISTS (SELECT 1 FROM categories_category_types t WHERE t.category_id = c.id AND t.type_id = ?)",
            [self::RETAIL_TYPE, self::RETAIL_TYPE],
        );

        // ── Badges ─────────────────────────────────────────────────────────
        $this->addSql('CREATE TABLE badges (
            id UUID NOT NULL,
            slug VARCHAR(50) NOT NULL,
            name VARCHAR(100) NOT NULL,
            emoji VARCHAR(16) NOT NULL,
            "order" INT DEFAULT 0 NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_badges_slug ON badges (slug)');
        $this->addSql('CREATE TABLE business_badges (
            business_id UUID NOT NULL,
            badge_id UUID NOT NULL,
            PRIMARY KEY(business_id, badge_id))');
        $this->addSql('CREATE INDEX idx_business_badges_badge ON business_badges (badge_id)');
        $this->addSql('CREATE TABLE badge_categories (
            badge_id UUID NOT NULL,
            category_id UUID NOT NULL,
            PRIMARY KEY(badge_id, category_id))');

        foreach (self::BADGES as $slug => [$emoji, $order, $groups]) {
            $this->addSql(
                'INSERT INTO badges (id, slug, name, emoji, "order") VALUES (?, ?, ?, ?, ?)',
                [self::idFor('badge.' . $slug), $slug, 'badge.' . $slug, $emoji, $order],
            );
            foreach ($groups as $group) {
                $this->addSql(
                    'INSERT INTO badge_categories (badge_id, category_id) VALUES (?, ?)',
                    [self::idFor('badge.' . $slug), self::idFor($group)],
                );
            }
        }

        // Quien era «Ecológico» se lo lleva como badge antes de que la
        // categoría desaparezca.
        $this->addSql(
            "INSERT INTO business_badges (business_id, badge_id)
             SELECT b.id, (SELECT id FROM badges WHERE slug = 'eco')
               FROM business b JOIN categories c ON c.id = b.category_id
              WHERE c.slug = 'eco'",
        );

        // ── Fusiones ───────────────────────────────────────────────────────
        foreach (self::MERGES as $from => $to) {
            foreach (['business', 'products', 'geostories'] as $table) {
                $this->addSql(
                    "UPDATE {$table}
                        SET category_id = (SELECT id FROM categories WHERE slug = ?)
                      WHERE category_id = (SELECT id FROM categories WHERE slug = ?)",
                    [$to, $from],
                );
            }
            $this->addSql(
                'UPDATE categories SET deleted_at = NOW(), updated_at = NOW() WHERE slug = ? AND deleted_at IS NULL',
                [$from],
            );
        }

        // Los negocios de `culture` (teatros) pasan a Turismo · Cultura, por
        // clasificar. La categoría se queda: es la de los vídeos de
        // influencers en el feed de Turismo, y esos vídeos no se tocan.
        $this->addSql(
            "UPDATE business
                SET category_id = (SELECT id FROM categories WHERE slug = 'tourism-culture')
              WHERE category_id = (SELECT id FROM categories WHERE slug = 'culture')",
        );
        // Sus productos no: un producto lleva categoría propia (un libro en
        // una tienda de regalos es `bookshop`), y sólo se mueve si la suya
        // desaparece, que es lo que ya hacen las fusiones.
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Las fusiones mueven negocios, productos y vídeos sin guardar de dónde venían.',
        );
    }

    private function insertCategory(string $slug, int $order, ?string $parent, ?string $section): void
    {
        $this->addSql(
            "INSERT INTO categories (id, name, slug, \"order\", mode, parent_id, section, created_at, updated_at)
             SELECT ?::uuid, ?, ?, ?, 'business',
                    (SELECT id FROM categories WHERE slug = ?), ?, NOW(), NOW()
              WHERE NOT EXISTS (SELECT 1 FROM categories WHERE slug = ?)",
            [self::idFor($slug), 'category.' . $slug, $slug, $order, $parent, $section, $slug],
        );
    }
}
