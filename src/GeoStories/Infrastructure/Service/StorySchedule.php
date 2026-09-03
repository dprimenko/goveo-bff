<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Service;

use App\Categories\Domain\CategoryRepository;
use App\GeoStories\Domain\GeoStory;

/**
 * Quién tiene vigencia y cuál es.
 *
 * Dos categorías caducan, y por motivos distintos:
 *
 * - **Eventos** valen mientras no hayan terminado. Se pide la hora de inicio
 *   —quien publica su concierto la sabe— y la de fin es opcional, porque muchas
 *   veces no se sabe; sin ella se asume la duración por defecto del dominio.
 * - **Noticias** son de hoy por definición: empiezan al publicarse y caducan a
 *   la semana, sin preguntar nada.
 *
 * El resto no tiene vigencia y se queda en blanco. Vive en un servicio y no en
 * cada controlador porque el alta y la edición tienen que aplicar exactamente
 * la misma regla: si una de las dos se olvida del fin por defecto, el vídeo se
 * queda a la vista para siempre.
 */
final class StorySchedule
{
    /** Códigos de error, tal cual los devuelve la API. */
    public const ERROR_START_REQUIRED = 'event_start_required';
    public const ERROR_INVALID_RANGE  = 'event_end_before_start';
    public const ERROR_INVALID_DATE   = 'invalid_date';

    private const EVENTS = 'events';
    private const NEWS   = 'news';

    public function __construct(
        private readonly CategoryRepository $categories,
    ) {}

    /**
     * Comprueba lo que se pide **sin necesitar el vídeo**, para poder rechazar
     * un alta antes de subir el fichero: con la fecha mal, subir primero sería
     * dejar el vídeo colgado en Bunny sin fila que lo apunte, y nadie lo
     * borraría después.
     *
     * @return ?string el código de error, o `null` si vale.
     */
    public function validateNew(?string $categoryId, ?string $rawStart, ?string $rawEnd): ?string
    {
        if ($this->slugOf($categoryId) !== self::EVENTS) {
            return null;
        }

        $start = $this->parse($rawStart);
        $end   = $this->parse($rawEnd);

        if ($start === false || $end === false) {
            return self::ERROR_INVALID_DATE;
        }
        if ($start === null) {
            return self::ERROR_START_REQUIRED;
        }
        if ($end !== null && $end <= $start) {
            return self::ERROR_INVALID_RANGE;
        }

        return null;
    }

    /**
     * Pone al vídeo la vigencia que le toca por su categoría.
     *
     * `$rawStart`/`$rawEnd` llegan como los manda el cliente (ISO-8601, o
     * `null` si no vienen en la petición). En la edición, no mandarlos significa
     * «déjalo como está»: sólo se recalcula cuando llega algo o cuando el vídeo
     * aún no tiene fechas —el caso de cambiar la categoría a Eventos—.
     *
     * @return ?string el código de error si lo pedido no vale, o `null` si todo
     *                 bien. El vídeo no se toca cuando hay error.
     */
    public function apply(
        GeoStory $story,
        ?string $categoryId,
        ?string $rawStart,
        ?string $rawEnd,
    ): ?string {
        $slug = $this->slugOf($categoryId);

        if ($slug === self::NEWS) {
            // Se respeta la que ya tenga: reeditar una noticia no le regala otra
            // semana de vida, que sería la forma de que no caducara nunca.
            if ($story->getStartedAt() === null) {
                $story->scheduleNews();
            }

            return null;
        }

        if ($slug !== self::EVENTS) {
            $story->clearSchedule();

            return null;
        }

        $start = $this->parse($rawStart);
        $end   = $this->parse($rawEnd);

        if ($start === false || $end === false) {
            return self::ERROR_INVALID_DATE;
        }

        $start ??= $story->getStartedAt();
        if ($start === null) {
            return self::ERROR_START_REQUIRED;
        }

        // Sólo se hereda el fin guardado cuando no se toca el inicio: si el
        // inicio se mueve, un fin viejo podría quedar por detrás.
        if ($end === null && $rawStart === null) {
            $end = $story->getEndedAt();
        }

        if ($end !== null && $end <= $start) {
            return self::ERROR_INVALID_RANGE;
        }

        $story->scheduleEvent($start, $end);

        return null;
    }

    private function slugOf(?string $categoryId): ?string
    {
        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        // La columna es `uuid`: buscar un slug por id revienta la consulta en
        // Postgres en vez de devolver «no encontrado».
        $category = preg_match('/^[0-9a-f-]{36}$/i', $categoryId)
            ? $this->categories->findById($categoryId)
            : $this->categories->findBySlug($categoryId);

        return $category?->getSlug();
    }

    /**
     * @return \DateTimeImmutable|null|false `null` = no viene (o viene vacío,
     *         que es como se quita el fin), `false` = viene y no se entiende.
     */
    private function parse(?string $raw): \DateTimeImmutable|null|false
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return false;
        }
    }
}
