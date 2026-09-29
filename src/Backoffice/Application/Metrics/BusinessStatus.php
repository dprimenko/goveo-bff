<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Metrics;

/**
 * **Qué significa cada estado de un negocio**, escrito una sola vez.
 *
 * Son condiciones SQL sobre la tabla `business` con alias `b`. Las usan las
 * métricas del panel y la cola de revisión (`ListBusinessReviewsController`),
 * para que «pendientes» sea el mismo número en la tarjeta y en la pestaña: si
 * cada uno lo contara a su manera, la tarjeta diría 12 y la lista enseñaría 9, y
 * lo siguiente sería preguntar cuál de los dos miente.
 *
 * Todas excluyen lo archivado (`deleted_at`), que es también donde acaba lo
 * rechazado.
 */
final class BusinessStatus
{
    /** Sin decidir: ni validado ni rechazado. */
    private const UNREVIEWED = 'b.deleted_at IS NULL AND b.verified_at IS NULL AND b.rejected_at IS NULL';

    /**
     * Tiene un cobro pendiente y **ninguno resuelto**: un alta de pago que se
     * quedó en la pasarela. Los importados no tienen suscripción y las tarifas
     * gratuitas nacen activas, así que no caen aquí.
     */
    private const ONLY_UNPAID_SUBSCRIPTION = "EXISTS (
                               SELECT 1 FROM business_subscriptions pend
                                WHERE pend.business_id = b.id
                                  AND pend.status = 'pending_payment'
                                  AND NOT EXISTS (
                                      SELECT 1 FROM business_subscriptions ok
                                       WHERE ok.business_id = b.id
                                         AND ok.status <> 'pending_payment'
                                  )
                           )";

    /**
     * **Pendiente**: alta de una persona que espera a que alguien la valide.
     *
     * Sin lo que trajo el scraping —entra de golpe y tiene su pestaña— y sin las
     * altas esperando el pago: validarlas sería publicar a quien no ha pagado.
     */
    public const PENDING = self::UNREVIEWED . ' AND b.external_ref IS NULL AND NOT ' . self::ONLY_UNPAID_SUBSCRIPTION;

    /** Alta de pago sin pagar todavía. No está en la cola: no hay nada que validar. */
    public const AWAITING_PAYMENT = self::UNREVIEWED . ' AND b.external_ref IS NULL AND ' . self::ONLY_UNPAID_SUBSCRIPTION;

    /** Salas que creó el scraping de eventos y nadie ha revisado. */
    public const SCRAPED = self::UNREVIEWED . ' AND b.external_ref IS NOT NULL';

    /** **Validado**: el equipo lo ha revisado y sale en la app. */
    public const VERIFIED = 'b.deleted_at IS NULL AND b.verified_at IS NOT NULL';

    /**
     * **Completo**: la ficha tiene lo mínimo para que merezca la pena abrirla.
     *
     * - **Nombre** y **avatar** (el logo redondo), no vacíos.
     * - **Algo publicado**: un vídeo o foto validado, o un producto publicado,
     *   ninguno borrado. Lo que espera moderación o es un borrador no lo ve
     *   nadie, y la pregunta es qué encuentra quien abre la ficha.
     *
     * La portada (`main_image`) no se exige: la tienen casi todos (452 de 454
     * validados en la copia local) y pedirla no movería la cifra. Los eventos del
     * scraping cuentan como vídeos de su sala: están en su ficha.
     */
    public const FILLED = "NULLIF(btrim(b.name), '') IS NOT NULL
        AND NULLIF(btrim(b.avatar), '') IS NOT NULL
        AND (
            EXISTS (SELECT 1 FROM geostories fg
                     WHERE fg.business_id = b.id AND fg.deleted_at IS NULL AND fg.verified_at IS NOT NULL)
         OR EXISTS (SELECT 1 FROM products fp
                     WHERE fp.business_id = b.id AND fp.deleted_at IS NULL AND fp.published_at IS NOT NULL)
        )";

    /**
     * **Activo** entre dos instantes (`[desde, hasta)`): ha subido un vídeo, una
     * foto o un producto.
     *
     * - Por la fecha de **alta** del contenido (`created_at`), no por la de
     *   publicación: lo que se mide es que el negocio hizo algo.
     * - **Cuenta lo borrado después**: un vídeo que se rechazó también fue
     *   subirlo.
     * - **Sin lo del scraping** (`external_ref`): esos eventos no los sube la
     *   sala, y harían «activa» a cualquiera con cartelera.
     *
     * Lo que sube el equipo desde el panel en nombre del negocio cuenta igual: la
     * base no guarda quién lo subió.
     *
     * @param string $from nombre del parámetro con el inicio (sin los dos puntos)
     * @param string $to   nombre del parámetro con el final, excluido
     */
    public static function activeBetween(string $from, string $to): string
    {
        return "(
            EXISTS (SELECT 1 FROM geostories ag
                     WHERE ag.business_id = b.id AND ag.external_ref IS NULL
                       AND ag.created_at >= :{$from} AND ag.created_at < :{$to})
         OR EXISTS (SELECT 1 FROM products ap
                     WHERE ap.business_id = b.id
                       AND ap.created_at >= :{$from} AND ap.created_at < :{$to})
        )";
    }
}
