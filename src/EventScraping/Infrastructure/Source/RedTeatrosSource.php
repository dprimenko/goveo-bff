<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Application\Shows;
use App\EventScraping\Domain\EventSource;
use App\EventScraping\Domain\ScrapedEvent;
use App\EventScraping\Domain\ScrapedVenue;
use App\EventScraping\Infrastructure\Html;
use App\EventScraping\Infrastructure\WebPage;

/**
 * Red de Teatros de la Comunidad de Madrid (madrid.org/clas_artes/red), sólo la
 * programación de **público familiar**: teatro, títeres, música y danza para
 * niños que giran por los teatros municipales de ~70 pueblos de la región.
 *
 * La página `familiar.html` lista los espectáculos de la temporada (cartel,
 * compañía, edad recomendada) y la ficha de cada uno dice dónde y cuándo se
 * representa: «Leganés · CENTRO CULTURAL RIGOBERTA MENCHÚ · 13 de noviembre -
 * 18:30 h.». Una petición por espectáculo (~35).
 *
 * - **Un evento por espectáculo y sala**: el mismo en Getafe y en Leganés son
 *   dos sitios distintos; dos funciones en la misma sala se juntan (`Shows`).
 * - **Sin año en las fechas**: la temporada va en el título de la página
 *   («Programación 2º Semestre 2026»). Deducirlo como `SpanishDate` mandaría
 *   las funciones de julio, ya pasadas, al año que viene.
 * - **Las salas se sacaron una vez** de las páginas de cada municipio (su
 *   dirección) y de la geocodificación de Google (`VENUES`). Una sala nueva que
 *   no esté se queda en el centro de su pueblo (`MUNICIPALITIES`), con la
 *   dirección «Sala, Pueblo»: mejor eso que perder la función.
 * - Las de **Madrid capital** se saltan: son centros culturales del
 *   Ayuntamiento y ya llegan por `madrid-datos`.
 * - Plazas, parques y «otros espacios» no son la sala de nadie: van sin nombre
 *   de sala, como en `comunidad-madrid`, para no acabar en un negocio que se
 *   llame igual.
 */
final class RedTeatrosSource implements EventSource
{
    private const SITE    = 'https://www.madrid.org/clas_artes/red/';
    private const LISTING = self::SITE . 'familiar.html';

    /** Escenarios al aire libre o sin nombrar. */
    private const OUTDOORS = '/^(plaza|parque|jard[ií]n|recinto|otros espacios|anfiteatro)\b/iu';

    /**
     * «pueblo|sala» (sin tildes, en minúsculas) → [lat, lng, dirección].
     *
     * @var array<string, array{0: float, 1: float, 2: string}>
     */
    private const VENUES = [
        'ajalvir|casa cultura' => [40.5302674, -3.4839237, '28864 Ajalvir, Madrid'],
        'alcobendas|centro cultural pablo iglesias' => [40.5430007, -3.6439881, 'P.º de la Chopera, 59, 28100 Alcobendas, Madrid'],
        'alcobendas|plaza de la hoja roja' => [40.5515697, -3.6571885, 'Av. de la Magia, 4, 28100 Alcobendas, Madrid'],
        'alcobendas|teatro auditorio ciudad de alcobendas' => [40.5481819, -3.6419688, 'C. de Blas de Otero, 4, 28100 Alcobendas, Madrid'],
        'alcorcon|auditorio paco de lucia' => [40.3369154, -3.8225421, 'C. Parque Ferial, 4, 28923 Alcorcón, Madrid'],
        'alcorcon|centro cultural vinagrande' => [40.3482819, -3.8049202, 'C. Parque Ordesa, 5, 28924 Alcorcón, Madrid'],
        'alcorcon|plaza reyes de espana' => [40.3497034, -3.8284839, 'Plaza de los Reyes de España, C. la Iglesia, 5, 28921 Alcorcón, Madrid'],
        'alcorcon|teatro municipal buero vallejo' => [40.3383632, -3.8345892, 'Av. las Retamas, 28922 Alcorcón, Madrid'],
        'algete|auditorio centro municipal joan manuel serrat' => [40.6004288, -3.5056209, 'C. de Emilia Pardo Bazán, 7, 28110 Algete, Madrid'],
        'alpedrete|centro cultural alpedrete' => [40.6570049, -4.0237572, 'Pl. de, Pl. Francisco Rabal, 2, 28430 Alpedrete, Madrid'],
        'alpedrete|plaza francisco rabal' => [40.6568241, -4.0235378, 'Pl. Francisco Rabal, 28430 Alpedrete, Madrid'],
        'arganda del rey|auditorio montserrat caballe' => [40.2959872, -3.431946, 'C. Mar Alborán, 1, 28500 Arganda del Rey, Madrid'],
        'arganda del rey|plaza de la constitucion' => [40.300692, -3.4381557, 'Pl. de la Constitución, 28500 Arganda del Rey, Madrid'],
        'arroyomolinos|auditorium del centro de las artes' => [40.2734592, -3.915848, 'C. Madrid, 25, 28939 Arroyomolinos, Madrid'],
        'becerril de la sierra|sala real' => [40.7157234, -3.9902421, 'C. Real, 4, 28490 Becerril de la Sierra, Madrid'],
        'boadilla del monte|auditorio municipal raphael' => [40.4028224, -3.8877512, 'Av. Isabel de Farnesio, 16, 28660 Boadilla del Monte, Madrid'],
        'chapineria|auditorio municipal' => [40.3780064, -4.2063479, 'C. Rodetas, 28694 Chapinería, Madrid'],
        'chinchon|plaza mayor' => [40.1401375, -3.4227818, 'Pl. Mayor, 28370 Chinchón, Madrid'],
        'ciempozuelos|sala multifuncional' => [40.1552877, -3.6293809, 'Av. de Belén, 39, 28350 Ciempozuelos, Madrid'],
        'cobena|casa de la cultura' => [40.5678846, -3.5046856, 'Pl. de la Villa, 3, 28863 Cobeña, Madrid'],
        'collado mediano|teatro municipal carlos saura' => [40.6930136, -4.0275817, 'C. Real, 36, 28450 Collado Mediano, Madrid'],
        'collado villalba|teatro casa de la cultura de collado villalba' => [40.6330485, -4.0028605, 'C. Real, 68, 28400 Collado Villalba, Madrid'],
        'colmenar de oreja|teatro municipal dieguez' => [40.108025, -3.3906084, 'C. del Convento, 5, 28380 Colmenar de Oreja, Madrid'],
        'colmenar viejo|auditorio municipal colmenar viejo' => [40.6655409, -3.7737545, 'CC El Mirador, Molino de viento, S/N, 28770 Colmenar Viejo, Madrid'],
        'colmenarejo|teatro municipal de colmenarejo' => [40.5596713, -4.0174323, 'C. de la Inmaculada, 2, 28270 Colmenarejo, Madrid'],
        'coslada|teatro municipal' => [40.4264877, -3.5518921, 'Av. de los Príncipes de España, 2, 28823 Coslada, Madrid'],
        'cubas de la sagra|centro de artes escenicas juan perez zuniga' => [40.1870523, -3.8383807, '28978 Cubas de la Sagra, Madrid'],
        'el alamo|plaza de la constitucion de el alamo' => [40.2305557, -3.9906564, 'Pl. de la Constitución, 28607 El Álamo, Madrid'],
        'fuenlabrada|teatro tomas y valiente' => [40.2925985, -3.7907561, 'C/ de Leganés, 51, 28945 Fuenlabrada, Madrid'],
        'galapagar|teatro jacinto benavente' => [40.5828654, -4.0084956, 'C. Guadarrama, 66, 28260 Galapagar, Madrid'],
        'getafe|espacio mercado' => [40.3051393, -3.7317245, 'Pl. de la Constitución, 5, 28901 Getafe, Madrid'],
        'getafe|teatro auditorio federico garcia lorca' => [40.31018, -3.7329253, 'C. Ramon y Cajal, 22, 28902 Getafe, Madrid'],
        'guadarrama|centro cultural la torre' => [40.6734828, -4.0865164, 'C. de la Torre, 1D, 28440 Guadarrama, Madrid'],
        'hoyo de manzanares|teatro municipal las ciguenas' => [40.6220549, -3.9061728, 'Pl. de la Iglesia, 28240 Hoyo de Manzanares, Madrid'],
        'las rozas de madrid|auditorio joaquin rodrigo' => [40.4951344, -3.8774843, 'Av. del Polideportivo, 18, 28231 Las Rozas de Madrid, Madrid'],
        'las rozas de madrid|teatro federico garcia lorca cc perez de la riva' => [40.4895005, -3.8776067, 'C. Principado de Asturias, 28, 28231 Las Rozas de Madrid, Madrid'],
        'leganes|centro cultural rigoberta menchu' => [40.3360933, -3.7407799, 'Av. Rey Juan Carlos I, 100, 28916 Leganés, Madrid'],
        'majadahonda|casa de la cultura carmen conde' => [40.4693862, -3.8707424, 'Pl. Cristóbal Colón, 28220 Majadahonda, Madrid'],
        'manzanares el real|sala el rodaje' => [40.7264576, -3.8647999, 'Av. de Madrid, 1, 28410 Manzanares el Real, Madrid'],
        'meco|centro cultural antonio llorente' => [40.5546822, -3.3293236, 'Pl. de España, 4, 28880 Meco, Madrid'],
        'mejorada del campo|teatro casa de cultura de mejorada del campo' => [40.3963176, -3.4830487, 'Pl. de la Ilustración, 9, 28840 Mejorada del Campo, Madrid'],
        'moraleja de enmedio|centro cultural el cerro' => [40.2619592, -3.8595628, 'C. Mirasierra, 2, 28950 Moraleja de Enmedio, Madrid'],
        'moralzarzal|teatro municipal de moralzarzal' => [40.6798814, -3.9662988, 'Av. Salvador Sanchez Frascuelo, 26, 28411 Moralzarzal, Madrid'],
        'morata de tajuna|casa de la cultura francisco gonzalez' => [40.2313633, -3.4410724, 'C. la Tarayuela, 28530 Morata de Tajuña, Madrid'],
        'mostoles|centro socio cultural el soto' => [40.3257647, -3.881488, 'Av. de los Deportes, 15, 28935 Móstoles, Madrid'],
        'navalcarnero|otros espacios plaza segovia' => [40.2875041, -4.0141874, 'Pl. de Segovia, 28600 Navalcarnero, Madrid'],
        'navalcarnero|teatro municipal centro' => [40.2894443, -4.0136003, 'Plaza del Teatro, 28600 Navalcarnero, Madrid'],
        'paracuellos de jarama|teatro centro cultural' => [40.505498, -3.5335246, 'C. Ronda de las Cuestas, 39, 28860 Paracuellos de Jarama, Madrid'],
        'parla|teatro dulce chacon' => [40.2363914, -3.778681, 'C. Rosa Manzano, 3, 28981 Parla, Madrid'],
        'parla|teatro jaime salom' => [40.2383667, -3.7683503, 'C. San Antón, 46, 28982 Parla, Madrid'],
        'pinto|teatro municipal francisco rabal' => [40.2434637, -3.6933269, 'C. de las Alpujarras, 28320 Pinto, Madrid'],
        'pozuelo de alarcon|mira teatro' => [40.4452976, -3.8094539, 'Cam. de las Huertas, 42, 28224 Pozuelo de Alarcón, Madrid'],
        'pozuelo de alarcon|parque prados de torrejon' => [40.4487182, -3.8041654, 'Parque Prados de Torrejón, 28224 Pozuelo de Alarcón, Madrid'],
        'san agustin del guadalix|casa de cultura agustin de tagaste' => [40.6823234, -3.6164465, 'C. Andalucía, 1, 28750 San Agustín del Guadalix, Madrid'],
        'san martin de la vega|auditorio municipal' => [40.2112411, -3.5813662, 'Av. de Ntra. Sra. de la Vega, 28330 San Martín de la Vega, Madrid'],
        'san martin de la vega|parque v centenario' => [40.211455, -3.5786036, 'Parque cuarto centenario, Av. del Dr. Manuel Jarabo, 28330 San Martín de la Vega, Madrid'],
        'san martin de valdeiglesias|anfiteatro de la estacion' => [40.3650154, -4.3981036, '28680 San Martín de Valdeiglesias, Madrid'],
        'san sebastian de los reyes|auditorio adolfo marsillach' => [40.5542457, -3.632592, 'Av. de Baunatal, 18, 28701 San Sebastián de los Reyes, Madrid'],
        'serranillos del valle|recinto ferial' => [40.2038847, -3.8862895, 'C. del Río, 1, 28979 Serranillos del Valle, Madrid'],
        'serranillos del valle|teatro municipal serranillos del valle' => [40.2044911, -3.8863661, 'Pl. de la Fuente, 28979 Serranillos del Valle, Madrid'],
        'soto del real|centro de arte y turismo' => [40.7545525, -3.7832348, 'Av. Víctimas del Terrorismo, 2, 28791 Soto del Real, Madrid'],
        'talamanca de jarama|centro cultural salon del puente' => [40.7448999, -3.5127368, 'C. de la Villa, 1, 28160 Talamanca de Jarama, Madrid'],
        'torrejon de ardoz|teatro jose maria rodero' => [40.4600525, -3.4742477, 'C. de Londres, 3, 28850 Torrejón de Ardoz, Madrid'],
        'torrelaguna|casa de la cultura' => [40.8277011, -3.5364121, 'Pl. de la Paz, 3, 28180 Torrelaguna, Madrid'],
        'torrelodones|teatro bulevar' => [40.5795649, -3.9567016, 'Av. de Rosario Manzaneque, 1, 28250 Torrelodones, Madrid'],
        'torres de la alameda|auditorio torres de la alameda las amapolas' => [40.4054309, -3.3642674, 'P.º de los Pozos, 4, 28813 Torres de la Alameda, Madrid'],
        'tres cantos|teatro municipal de tres cantos' => [40.5992479, -3.7110964, 'Pl. del Ayuntamiento, 2, 28760 Tres Cantos, Madrid'],
        'valdemorillo|casa de cultura giralt laporta' => [40.5006311, -4.0656117, 'C. la Paz, 28210 Valdemorillo, Madrid'],
        'valdemoro|teatro municipal juan prado' => [40.1922012, -3.6766249, 'C. Estrella de Elola, 27, 28341 Valdemoro, Madrid'],
        'velilla de san antonio|centro cultural auditorio mariana pineda' => [40.3654854, -3.4843805, 'Calle de Dr. Alcorta, 15, 28891 Velilla de San Antonio, Madrid'],
        'villa del prado|plaza mayor' => [40.2765538, -4.3057916, 'Pl. Mayor, 28630 Villa del Prado, Madrid'],
        'villalbilla|auditorio municipal emilia pardo bazan' => [40.4438513, -3.3633146, 'Av. de la Isabela Braganza, 15, 28810 Villalbilla, Madrid'],
        'villanueva de la canada|centro cultural la despernada' => [40.4496392, -4.0007883, 'C. Olivar, 10, 28691 Villanueva de la Cañada, Madrid'],
        'villanueva del pardillo|auditorio municipal sebastian cestero' => [40.4888964, -3.9628753, 'C. Recaudación, 3, 28229 Villanueva del Pardillo, Madrid'],
    ];

    /**
     * Centro de cada pueblo de la Red, para una sala que no esté en `VENUES`.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const MUNICIPALITIES = [
        'ajalvir' => [40.5302674, -3.4839237],
        'alcala de henares' => [40.4843898, -3.3688023],
        'alcobendas' => [40.5371361, -3.6370715],
        'alcorcon' => [40.3476651, -3.8251581],
        'algete' => [40.5964617, -3.5016452],
        'alpedrete' => [40.6594174, -4.0335697],
        'aranjuez' => [40.0305018, -3.6040527],
        'arganda del rey' => [40.3064308, -3.4471715],
        'arroyomolinos' => [40.2744815, -3.9111089],
        'becerril de la sierra' => [40.7102483, -3.9954969],
        'boadilla del monte' => [40.4312639, -3.8941115],
        'buitrago del lozoya' => [40.989823, -3.6383114],
        'camarma de esteruelas' => [40.5489089, -3.3784857],
        'cenicientos' => [40.2624728, -4.4658148],
        'chapineria' => [40.3790944, -4.2093828],
        'chinchon' => [40.1402665, -3.4221687],
        'ciempozuelos' => [40.157955, -3.6192473],
        'cobena' => [40.5683393, -3.5040753],
        'collado mediano' => [40.6927311, -4.0267258],
        'collado villalba' => [40.6308675, -4.0054458],
        'colmenar de arroyo' => [40.4185154, -4.1981541],
        'colmenar de oreja' => [40.1098823, -3.39055],
        'colmenar viejo' => [40.6626481, -3.7710457],
        'colmenarejo' => [40.5607261, -4.0177635],
        'coslada' => [40.4281746, -3.5602441],
        'cubas de la sagra' => [40.1910568, -3.8372005],
        'el alamo' => [40.2312494, -3.9884916],
        'fuenlabrada' => [40.2774625, -3.7943157],
        'galapagar' => [40.5765326, -4.0057563],
        'getafe' => [40.3082504, -3.7323934],
        'grinon' => [40.2141551, -3.8585623],
        'guadarrama' => [40.6733652, -4.090631],
        'hoyo de manzanares' => [40.6226405, -3.9082735],
        'la cabrera' => [40.8655887, -3.6154482],
        'las rozas de madrid' => [40.4953138, -3.8784732],
        'leganes' => [40.3319506, -3.7686545],
        'majadahonda' => [40.4736696, -3.8684458],
        'manzanares el real' => [40.7270997, -3.8648671],
        'meco' => [40.5551951, -3.3296591],
        'mejorada del campo' => [40.395861, -3.4838312],
        'moraleja de enmedio' => [40.2596189, -3.8606776],
        'moralzarzal' => [40.6763507, -3.9704867],
        'morata de tajuna' => [40.229576, -3.4366526],
        'mostoles' => [40.3232129, -3.8676291],
        'navalcarnero' => [40.2855681, -4.016639],
        'paracuellos de jarama' => [40.5056034, -3.5302108],
        'parla' => [40.2373062, -3.7739869],
        'pedrezuela' => [40.744654, -3.6027866],
        'pinto' => [40.2375052, -3.6967718],
        'pozuelo de alarcon' => [40.447343, -3.8073416],
        'rivas vaciamadrid' => [40.3518758, -3.532005],
        'san agustin del guadalix' => [40.6788466, -3.6163061],
        'san fernando de henares' => [40.4256252, -3.5314099],
        'san lorenzo de el escorial' => [40.5932366, -4.1472971],
        'san martin de la vega' => [40.2103049, -3.5787384],
        'san martin de valdeiglesias' => [40.3635617, -4.4035034],
        'san sebastian de los reyes' => [40.5589672, -3.6261976],
        'serranillos del valle' => [40.2056183, -3.8833494],
        'soto del real' => [40.7543795, -3.7832172],
        'talamanca de jarama' => [40.7451696, -3.5111468],
        'torrejon de ardoz' => [40.4567552, -3.4754967],
        'torrejon de la calzada' => [40.2016981, -3.7999099],
        'torrelaguna' => [40.8275222, -3.5395265],
        'torrelodones' => [40.5766078, -3.9293646],
        'torres de la alameda' => [40.4035847, -3.3629123],
        'tres cantos' => [40.600727, -3.7079745],
        'valdemorillo' => [40.500799, -4.067547],
        'valdemoro' => [40.1912635, -3.6740351],
        'valdeolmos alalpardo' => [40.6385042, -3.4531644],
        'velilla de san antonio' => [40.3708904, -3.4855079],
        'villa del prado' => [40.2773745, -4.3046507],
        'villalbilla' => [40.4329592, -3.3005573],
        'villanueva de la canada' => [40.4480803, -4.0020189],
        'villanueva del pardillo' => [40.4899552, -3.961547],
        'villaviciosa de odon' => [40.3626807, -3.9139109],
    ];

    public function __construct(private readonly WebPage $web) {}

    public function name(): string
    {
        return 'red-teatros';
    }

    public function city(): string
    {
        return 'Madrid';
    }

    public function fetch(): iterable
    {
        $html = $this->web->get(self::LISTING);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la programación familiar de la Red de Teatros');
        }

        $xp   = Html::xpath($html);
        $year = preg_match('/Semestre\s+(\d{4})/u', (string) Html::text($xp, '//title'), $m) ? (int) $m[1] : null;
        if ($year === null) {
            throw new \RuntimeException('La Red de Teatros no dice de qué temporada es su programación');
        }

        $passes  = [];
        $section = '';
        // En el orden de la página: el `h4` («TEATRO», «MÚSICA», «DANZA») y
        // después las tarjetas de esa sección.
        foreach ($xp->query('//h4 | //a[' . Html::hasClass('pro-obra') . ']') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            if ($node->nodeName === 'h4') {
                $section = mb_strtolower(trim($node->textContent));
                continue;
            }

            $href  = $node->getAttribute('href');
            $title = Html::clean(Html::text($xp, './/h3', $node), 200);
            // Relativa a la carpeta de la Red («fotos/programacion/bobo.jpg»):
            // `Html::absolute` la colgaría de la raíz del dominio. Es la foto
            // de 700×467; la de la ficha es una franja de 1500×700 que en el
            // cartel vertical se queda en nada.
            $src   = Html::attr($xp, './/img', 'src', $node);
            $image = $src === null ? null : (preg_match('#^https?://#', $src) ? $src : self::SITE . ltrim($src, '/'));
            if ($href === '' || $title === null) {
                continue;
            }

            array_push($passes, ...$this->performances(
                url: self::SITE . ltrim($href, '/'),
                title: $title,
                company: Html::text($xp, './/h5', $node),
                age: Html::text($xp, './/p', $node),
                image: $image,
                section: $section,
                year: $year,
            ));
        }

        return Shows::group($passes);
    }

    /** La ficha ya se leyó en `fetch`: hacían falta las fechas. */
    public function enrich(ScrapedEvent $event): ScrapedEvent
    {
        return $event;
    }

    /**
     * Ninguna sala se da de alta: son teatros municipales de toda la región
     * con una o dos funciones familiares cada uno, y el resto de su cartelera
     * no se lee. Los eventos se asignan igual a la sala si ya está en Goveo.
     */
    public function venueFor(ScrapedEvent $event): ?ScrapedVenue
    {
        return null;
    }

    /**
     * Las funciones de un espectáculo, una por pueblo y fecha.
     *
     * @return list<ScrapedEvent>
     */
    private function performances(string $url, string $title, ?string $company, ?string $age, ?string $image, string $section, int $year): array
    {
        $html = $this->web->get($url);
        if ($html === null) {
            return [];
        }

        $xp       = Html::xpath($html);
        $synopsis = Html::text($xp, '//p[strong[contains(., "SINOPSIS")]]/following-sibling::p[1]');
        $description = Html::clean(implode('. ', array_filter([
            $company !== null ? rtrim($company, '.') : null,
            $age !== null ? rtrim($age, '.') : null,
        ])) . ($synopsis !== null ? '. ' . $synopsis : ''), 400);
        [$subcategory, $subtype] = $this->classify($section, (string) $age);

        $out = [];
        foreach ($xp->query('//ul[' . Html::hasClass('info-sidebar') . '][li/strong]') as $block) {
            $town  = Html::text($xp, './li/strong', $block);
            $hall  = Html::text($xp, './li/span', $block);
            $when  = Html::text($xp, './li[3]', $block);
            $start = $this->start($when, $year);
            // Madrid capital llega por `madrid-datos` y Alcalá por su agenda (`alcala`).
            if ($town === null || $hall === null || $start === null || in_array($this->key($town), ['madrid', 'alcala de henares'], true)) {
                continue;
            }

            $place = self::VENUES[$this->key($town) . '|' . $this->key($hall)] ?? null;
            $point = $place ?? self::MUNICIPALITIES[$this->key($town)] ?? null;
            if ($point === null) {
                continue;
            }
            $outdoors = (bool) preg_match(self::OUTDOORS, $hall);
            $hallName = $outdoors ? '' : mb_convert_case(mb_strtolower($hall), \MB_CASE_TITLE);

            $out[] = new ScrapedEvent(
                source: $this->name(),
                externalId: $this->slug(basename($url, '.html') . '-' . $town . '-' . $hall),
                title: $title,
                start: $start,
                end: null,
                city: $town,
                venueName: $hallName,
                latitude: $point[0],
                longitude: $point[1],
                link: $url,
                description: $description,
                imageUrl: $image,
                detailUrl: $url,
                venueAddress: $place[2] ?? trim(($hallName !== '' ? $hallName . ', ' : '') . $town),
                subcategory: $subcategory,
                subtype: $subtype,
            );
        }

        return $out;
    }

    /**
     * Teatro, títeres, música y danza para niños: todo es espectáculo
     * (`events-kids-theater`). Lo que se anuncia para adultos —se cuela alguno
     * en la programación familiar— va a Escena.
     *
     * @return array{0: string, 1: string}
     */
    private function classify(string $section, string $age): array
    {
        if (preg_match('/^adultos/iu', trim($age))) {
            return str_contains($section, 'danza')
                ? ['events-stage', 'events-stage-dance']
                : ['events-stage', 'events-stage-theater'];
        }

        return ['events-kids', 'events-kids-theater'];
    }

    /** «13 de noviembre - 18:30 h.», con el año de la temporada. */
    private function start(?string $text, int $year): ?\DateTimeImmutable
    {
        if ($text === null || !preg_match('/(\d{1,2})\s+de\s+([a-záéíóú]+)/iu', $text, $d)) {
            return null;
        }
        $month = SpanishDate::month($d[2]);
        if ($month === null || !checkdate($month, (int) $d[1], $year)) {
            return null;
        }

        $day = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->setDate($year, $month, (int) $d[1])->setTime(0, 0);

        return preg_match('/(\d{1,2})[:.](\d{2})/', $text, $t) ? $day->setTime((int) $t[1], (int) $t[2]) : $day;
    }

    /** Sin tildes, en minúsculas y con espacios simples: la clave de las tablas. */
    private function key(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ç' => 'c']);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    private function slug(string $text): string
    {
        return mb_substr(str_replace(' ', '-', $this->key($text)), 0, 200);
    }
}
