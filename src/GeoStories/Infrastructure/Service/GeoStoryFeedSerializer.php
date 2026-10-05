<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Service;

use App\GeoStories\Domain\GeoStory;
use App\GeoStories\Domain\GeoStoryWithDistance;

/**
 * La forma de cada vídeo en `/public/geostories`, y la de los Guardados.
 *
 * Vive aquí y no en el controlador del feed porque «Guardados» tiene que
 * devolver **exactamente** lo mismo: la app pasa los dos por el mismo mapper, y
 * un campo que se añadiera sólo en uno rompería la tarjeta en el otro.
 */
final class GeoStoryFeedSerializer
{
    /**
     * @param ?int $processingProgress cuánto lleva Bunny codificándolo (0-100),
     *                                 sólo mientras se procesa y en la vista de su dueño
     */
    public static function serialize(GeoStoryWithDistance $s, ?int $processingProgress = null): array
    {
        return [
            'id'               => $s->id,
            'title'            => $s->title,
            'description'      => $s->description,
            'thumbnail'        => $s->thumbnail,
            'url'              => $s->url,
            'status'           => $s->status,
            // Cuánto lleva Bunny codificándolo (0-100), sólo mientras se procesa
            // y en la vista de su dueño, que es quien lo ve en «Procesando».
            'processing_progress' => $s->status === 'processing' && $s->providerVideoId !== null
                ? $processingProgress
                : null,
            // Qué es esto: un vídeo con reproductor o una foto. La tarjeta lo
            // necesita antes de montar nada.
            'media_type'       => $s->mediaType,
            // Enlace externo, plano como en el producto: vive en `meta` porque
            // no es de nuestro dominio, pero el cliente no tiene que bucear.
            'link_url'         => self::linkUrl($s->meta),
            'link_action'      => self::linkAction($s->meta),
            'meta'             => $s->meta,
            'likes'            => $s->likes,
            'lat'              => $s->lat,
            'long'             => $s->long,
            'dist_meters'      => $s->distMeters,
            'started_at'       => $s->startedAt?->format(\DateTimeInterface::ATOM),
            'ended_at'         => $s->endedAt?->format(\DateTimeInterface::ATOM),
            'created_at'       => $s->createdAt?->format(\DateTimeInterface::ATOM),
            'verified_at'      => $s->verifiedAt?->format(\DateTimeInterface::ATOM),
            'influencer_id'    => $s->influencerId,
            'influencer_name'  => $s->influencerName,
            'influencer_avatar' => $s->influencerAvatar,
            'business_id'      => $s->businessId,
            'business_name'    => $s->businessName,
            'business_avatar'  => $s->businessAvatar,
            'business_meta'    => $s->businessMeta,
            'category_id'      => $s->categoryId,
            'category_name'    => $s->categoryName,
            'category_slug'    => $s->categorySlug,
            'subcategory_id'   => $s->subcategoryId,
            'subcategory_slug' => $s->subcategorySlug,
            'subcategory_name' => $s->subcategoryName,
        ];
    }

    /** @param mixed $meta */
    private static function linkUrl($meta): ?string
    {
        $url = is_array($meta) ? ($meta['link_url'] ?? null) : null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** @param mixed $meta */
    private static function linkAction($meta): ?string
    {
        if (self::linkUrl($meta) === null) {
            return null;
        }

        $action = is_array($meta) ? ($meta['link_action'] ?? null) : null;

        // Sin acción guardada el botón sigue teniendo que decir algo, y de un
        // vídeo lo que casi siempre se quiere es ampliar información.
        return in_array($action, GeoStory::LINK_ACTIONS, true) ? $action : 'info';
    }
}
