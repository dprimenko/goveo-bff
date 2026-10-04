<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Infrastructure\Html;

/**
 * Agenda de la Concejalía de Cultura de Alcalá de Henares (culturalcala.es):
 * Teatro Salón Cervantes, las salas de exposiciones del casco (Santa María la
 * Rica, Capilla del Oidor), Gilitos, el Auditorio Paco de Lucía y lo que se hace
 * en la calle (el Mercado Cervantino).
 *
 * La portada trae toda la temporada en datos estructurados (EventON), con
 * cartel, sala y hora. El tipo («Teatro», «Música y Danza», «Programación
 * Infantil»…) no va en los datos sino en la tarjeta de cada evento de la misma
 * página: se lee de ahí por el título.
 *
 * Lo del Corral de Comedias lo lee `corral-alcala`, con sus funciones exactas;
 * lo de la Red de Teatros en el Salón Cervantes sale aquí, no en
 * `red-teatros-municipios`.
 */
final class AlcalaSource extends MunicipalJsonLdSource
{
    private const HOME = 'https://culturalcala.es/';

    /** Conferencias y presentaciones de libros: no son planes de agenda. */
    private const SKIP_TYPES = ['Literatura y Conferencias'];

    /** @var array<string, list<string>> título (slug) => tipos de la tarjeta */
    private array $types = [];

    public function name(): string
    {
        return 'alcala';
    }

    protected function municipality(): string
    {
        return 'Alcalá de Henares';
    }

    protected function homeUrl(): string
    {
        return self::HOME;
    }

    protected function pages(): iterable
    {
        $html = $this->web->get(self::HOME);
        if ($html === null) {
            throw new \RuntimeException('No se pudo descargar la agenda de CulturAlcalá');
        }

        $xp = Html::xpath($html);
        foreach ($xp->query('//a[' . Html::hasClass('evcal_list_a') . ']') as $card) {
            $title = Html::text($xp, './/span[' . Html::hasClass('evcal_event_title') . ']', $card);
            if ($title === null) {
                continue;
            }
            foreach ($xp->query('.//em[' . Html::hasClass('evoetet_val') . ']/@data-v', $card) as $type) {
                $this->types[$this->slug($title)][] = trim($type->nodeValue ?? '');
            }
        }

        yield $html;
    }

    protected function venues(): array
    {
        $site = self::HOME;

        return [
            'teatro-salon-cervantes'                    => ['Teatro Salón Cervantes', 'C. de Cervantes, 7, 28801 Alcalá de Henares', 40.4834571, -3.3661106, 'culture-shows', $site],
            'antiguo-hospital-de-santa-maria-la-rica'   => ['Antiguo Hospital de Santa María la Rica', 'C. Sta. María la Rica, 3, 28801 Alcalá de Henares', 40.4802469, -3.367931, 'tourism-museums', $site],
            'gilitos-laboratorio-de-creacion-alcala'    => ['Gilitos - Laboratorio de Creación', 'C. Padre Llanos, 2, 28806 Alcalá de Henares', 40.4921751, -3.3666232, 'culture-shows', $site],
            'auditorio-municipal-paco-de-lucia'         => ['Auditorio Municipal Paco de Lucía', 'Calle Ntra. Sra. del Pilar, s/n, 28803 Alcalá de Henares', 40.4710372, -3.3756446, 'culture-shows', $site],
            'capilla-del-oidor'                         => ['Capilla del Oidor', 'Pl. Rodríguez Marín, 28801 Alcalá de Henares', 40.4815886, -3.3633671, 'tourism-museums', $site],
            'hospital-de-antezana'                      => ['Hospital de Antezana', 'C. Mayor, 46, 28801 Alcalá de Henares', 40.4822815, -3.3669328, null, null],
            'casa-de-la-entrevista'                     => ['Casa de la Entrevista', 'C. San Juan, 2, 28801 Alcalá de Henares', 40.4810986, -3.3696565, null, null],
            'la-corrala-escondida'                      => ['La Corrala Escondida', 'C. Damas, 9, 28801 Alcalá de Henares', 40.478588, -3.3685138, null, null],
            // Una plaza no es la sala de nadie: sin nombre, a la Agenda.
            'plaza-de-cervantes'                        => ['', 'Pl. de Cervantes, 28801 Alcalá de Henares', 40.48261, -3.3639927, null, null],
        ];
    }

    public function fetch(): iterable
    {
        foreach (parent::fetch() as $event) {
            $types = $this->types[$this->slug($event->title)] ?? [];
            if ($types !== [] && array_diff($types, self::SKIP_TYPES) === []) {
                continue;
            }
            [$subcategory, $subtype] = $this->byType($types, $event->title . ' ' . $event->description);

            yield $this->rebuild($event, subcategory: $subcategory, subtype: $subtype);
        }
    }

    /** Se clasifica en `fetch`, con el tipo de la tarjeta. */
    protected function classify(array $node): array
    {
        return [null, null];
    }

    /**
     * @param list<string> $types
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function byType(array $types, string $text): array
    {
        $type = mb_strtolower(implode(' ', $types));
        $text = mb_strtolower($text);

        if (str_contains($type, 'infantil')) {
            return $this->kids('infantil ' . $text) ?? ['events-kids', 'events-kids-family-plans'];
        }
        if (($kids = $this->kids($text)) !== null) {
            return $kids;
        }

        return match (true) {
            str_contains($text, 'flamenc')                         => ['events-flamenco', 'events-flamenco-show'],
            (bool) preg_match('/\bmercado|\bferia\b/u', $text)     => ['events-markets', 'events-markets-fairs'],
            str_contains($type, 'exposiciones')                    => ['events-art', 'events-art-temporary'],
            str_contains($type, 'talleres')                        => ['events-experiences', 'events-experiences-workshops'],
            str_contains($type, 'cine')                            => ['events-experiences', 'events-experiences-cinema'],
            str_contains($type, 'teatro')                          => ['events-stage', match (true) {
                (bool) preg_match('/\bmusical\b/u', $text)                    => 'events-stage-musicals',
                (bool) preg_match('/\b(mon[oó]logo|humor|comedia)\b/u', $text) => 'events-stage-comedy',
                default                                                       => 'events-stage-theater',
            }],
            str_contains($type, 'música') && (bool) preg_match('/\bdanza|ballet\b/u', $text) => ['events-stage', 'events-stage-dance'],
            str_contains($type, 'música')                          => ['events-small-concerts', null],
            str_contains($type, 'calle')                           => ['events-stage', null],
            default                                                => [null, null],
        };
    }
}
