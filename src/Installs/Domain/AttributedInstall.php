<?php

declare(strict_types=1);

namespace App\Installs\Domain;

/**
 * Una instalación de la app que Branch atribuye a un enlace: lo que manda la
 * app en su primera apertura (`+is_first_session` con `+clicked_branch_link`).
 *
 * **No lleva nada de quién**: ni dispositivo, ni usuario, ni IP. Sólo de dónde
 * venía el enlace, y eso es lo que permite contarlo sin pedir consentimiento —
 * a diferencia de Google Analytics, que no ve a quien rechaza la analítica.
 *
 * Todo lo que llega se normaliza aquí, porque la ruta es pública y lo que
 * entra acaba siendo una fila de la tabla:
 *
 * - `platform` y `kind` son **listas cerradas**. Una plataforma desconocida no
 *   es una instalación que contar (`fromPayload` devuelve `null`); un tipo de
 *   contenido desconocido cuenta como `other`.
 * - `feature` también, pero con salida: lo que no es nuestro —un enlace de
 *   campaña hecho a mano en el panel de Branch— cuenta como `other`.
 * - `channel` y `campaign` son texto libre en Branch (`instagram`,
 *   `verano-2026`…), así que se dejan en minúsculas, `[a-z0-9_-]` y con
 *   longitud máxima. Lo vacío se guarda como cadena vacía, no como `null`:
 *   forma parte de la clave de la tabla.
 */
final class AttributedInstall
{
    public const PLATFORMS = ['ios', 'android'];

    /** Los destinos de `services/branch/targets.ts` de la app, y `none` si el enlace no llevaba a nada. */
    public const KINDS = ['business', 'influencer', 'geostory', 'product', 'app', 'loyalty', 'none'];

    /**
     * Los `~feature` que ponen nuestros enlaces: la app al compartir (`store`,
     * `publisher`, `video`, `product`, `app`), la web al compartir o al llevar a
     * un contenido (`store`, `publisher`) y los botones de descarga de la web
     * (`web_to_app`). `marketing` es el que pone el panel de Branch a los suyos.
     */
    public const FEATURES = ['store', 'publisher', 'video', 'product', 'app', 'web_to_app', 'loyalty', 'marketing'];

    public const OTHER = 'other';

    public const MAX_CHANNEL = 32;
    public const MAX_CAMPAIGN = 64;

    private function __construct(
        public readonly string $platform,
        public readonly string $channel,
        public readonly string $feature,
        public readonly string $campaign,
        public readonly string $kind,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        $platform = self::slug($payload['platform'] ?? null, 10);
        if (!in_array($platform, self::PLATFORMS, true)) {
            return null;
        }

        $feature = self::slug($payload['feature'] ?? null, 32);
        $kind    = self::slug($payload['kind'] ?? null, 16);

        return new self(
            platform: $platform,
            channel:  self::slug($payload['channel'] ?? null, self::MAX_CHANNEL),
            feature:  $feature === '' || in_array($feature, self::FEATURES, true) ? $feature : self::OTHER,
            campaign: self::slug($payload['campaign'] ?? null, self::MAX_CAMPAIGN),
            kind:     $kind === '' ? 'none' : (in_array($kind, self::KINDS, true) ? $kind : self::OTHER),
        );
    }

    /**
     * La misma instalación con el texto libre recogido en `other`: lo que se
     * guarda cuando el día ya tiene demasiadas combinaciones distintas (ver
     * `InstallCounter`).
     */
    public function collapsed(): self
    {
        return new self($this->platform, self::OTHER, $this->feature, self::OTHER, $this->kind);
    }

    /** Minúsculas, sólo `[a-z0-9_-]` (lo demás, `_`) y recortado. Lo que no es texto, vacío. */
    private static function slug(mixed $value, int $max): string
    {
        if (!is_string($value) && !is_int($value)) {
            return '';
        }

        $slug = strtolower(trim((string) $value));
        $slug = (string) preg_replace('/[^a-z0-9_-]+/', '_', $slug);

        return substr(trim($slug, '_'), 0, $max);
    }
}
