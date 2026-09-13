<?php

declare(strict_types=1);

namespace App\Backoffice\Application;

use App\Business\Domain\Business;
use App\GeoStories\Domain\GeoStory;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Avisa al dueño de lo revisado de la decisión que se ha tomado.
 *
 * Es la otra mitad de [`ReviewQueueNotifier`]: ése avisa **hacia dentro** de que
 * hay algo que mirar, y éste **hacia fuera** de lo que se ha decidido. Sin esto,
 * quien daba de alta un negocio o subía un vídeo se quedaba esperando sin saber
 * nada: aprobar sólo cambiaba una fecha en la base de datos, y el interesado se
 * enteraba —si se enteraba— abriendo la app a ver si ya salía.
 *
 * Tres decisiones que explican la forma de esta clase:
 *
 *  - **No lanza nunca.** Que falle el correo no puede tumbar la revisión: la
 *    decisión ya está tomada y guardada, y quien revisa no tiene por qué ver un
 *    error de SMTP. Se registra y se sigue.
 *  - **Sin dirección no se manda** y se registra un aviso. Hay negocios
 *    importados sin correo, y no es un error que haya que hacer estallar.
 *  - **Redacta [`ReviewDecisionMessages`], no esta clase.** Aquí sólo se
 *    resuelve a quién se le manda y con qué enlace; así la redacción se lee
 *    (y se previsualiza) sin base de datos.
 *
 * Lo que **no** decide es *cuándo*: eso lo hacen los controladores de la cola,
 * que sólo llaman si la decisión ha cambiado de verdad. Aprobar dos veces lo
 * mismo es legítimo —el `PUT` es idempotente— pero mandar dos correos no.
 */
final class ReviewDecisionMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        private readonly string $fromAddress,
        /** Base de las URL públicas (goveo-astro), para enlazar el vídeo. */
        private readonly string $webUrl,
        /** Enlace que abre la app —o lleva a su tienda—, para «Entrar en mi cuenta». */
        private readonly string $appUrl,
    ) {}

    public function businessApproved(Business $business): void
    {
        $to = $this->businessEmail($business);

        $this->send($to, 'business.approved', $business->getId(), fn () => ReviewDecisionMessages::businessApproved(
            $business->getName() ?? 'Tu negocio',
            $this->appUrl,
        ));
    }

    public function businessRejected(Business $business): void
    {
        $to = $this->businessEmail($business);

        $this->send($to, 'business.rejected', $business->getId(), fn () => ReviewDecisionMessages::businessRejected(
            $business->getName() ?? 'tu negocio',
        ));
    }

    public function videoApproved(GeoStory $story): void
    {
        $to = $this->storyEmail($story);

        $this->send($to, 'video.approved', $story->getId(), fn () => ReviewDecisionMessages::videoApproved(
            $story->getTitle(),
            $this->videoUrl($story),
        ));
    }

    public function videoRejected(GeoStory $story): void
    {
        $to = $this->storyEmail($story);

        $this->send($to, 'video.rejected', $story->getId(), fn () => ReviewDecisionMessages::videoRejected(
            $story->getTitle(),
        ));
    }

    /**
     * Creador aprobado o rechazado.
     *
     * Todavía **no lo llama nadie**: el panel no tiene cola de creadores (no hay
     * `PUT /api/admin/influencers/{id}/approve`), así que `influencers.verified_at`
     * hoy sólo se toca a mano o en la importación. Los dos correos están escritos
     * porque el texto es lo que se ha decidido ahora, y el día que exista esa cola
     * lo único que falta es llamar aquí desde su controlador.
     */
    public function publisherApproved(string $influencerId): void
    {
        [$name, $to] = $this->influencer($influencerId);

        $this->send($to, 'publisher.approved', $influencerId, fn () => ReviewDecisionMessages::publisherApproved(
            $name,
            $this->appUrl,
        ));
    }

    public function publisherRejected(string $influencerId): void
    {
        [$name, $to] = $this->influencer($influencerId);

        $this->send($to, 'publisher.rejected', $influencerId, fn () => ReviewDecisionMessages::publisherRejected($name));
    }

    /**
     * A quién se avisa de un negocio: al dueño de la cuenta desde la que se dio
     * de alta. Si esa cuenta no tiene correo —hay fichas importadas así— se
     * busca entre sus gestores, y en último lugar el correo de facturación, que
     * es el que escribió en el formulario y el único seguro en un alta web.
     */
    private function businessEmail(Business $business): ?string
    {
        $email = $this->db->fetchOne(
            'SELECT email FROM users WHERE id = ? AND deleted_at IS NULL AND email IS NOT NULL',
            [$business->getCreatorId()],
        );

        if (!is_string($email) || $email === '') {
            $email = $this->db->fetchOne(
                'SELECT u.email
                   FROM business_managers bm
                   JOIN users u ON u.id = bm.user_id
                  WHERE bm.business_id = ?
                    AND bm.deleted_at IS NULL
                    AND u.deleted_at IS NULL
                    AND u.email IS NOT NULL
                  ORDER BY bm.created_at ASC
                  LIMIT 1',
                [$business->getId()],
            );
        }

        if (is_string($email) && $email !== '') {
            return $email;
        }

        $meta = $business->getMeta() ?? [];

        foreach ([$meta['billing']['email'] ?? null, $meta['email'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A quién se avisa de un vídeo: a su dueño, que es un negocio o un creador.
     * El vídeo no guarda correo propio, y no puede: quien lo sube puede tener
     * varios negocios y el aviso tiene que llegarle a la cuenta, no a la ficha.
     */
    private function storyEmail(GeoStory $story): ?string
    {
        $businessId = $story->getBusinessId();
        if ($businessId !== null) {
            $email = $this->db->fetchOne(
                'SELECT u.email
                   FROM business b
                   JOIN users u ON u.id = b.creator_id
                  WHERE b.id = ? AND u.deleted_at IS NULL AND u.email IS NOT NULL',
                [$businessId],
            );

            if (is_string($email) && $email !== '') {
                return $email;
            }

            $email = $this->db->fetchOne(
                'SELECT u.email
                   FROM business_managers bm
                   JOIN users u ON u.id = bm.user_id
                  WHERE bm.business_id = ?
                    AND bm.deleted_at IS NULL
                    AND u.deleted_at IS NULL
                    AND u.email IS NOT NULL
                  ORDER BY bm.created_at ASC
                  LIMIT 1',
                [$businessId],
            );

            return is_string($email) && $email !== '' ? $email : null;
        }

        $influencerId = $story->getInfluencerId();

        return $influencerId === null ? null : $this->influencer($influencerId)[1];
    }

    /** @return array{0: ?string, 1: ?string} nombre y correo del creador */
    private function influencer(string $influencerId): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT i.name, u.email
               FROM influencers i
               JOIN users u ON u.id = i.user_id
              WHERE i.id = ? AND u.deleted_at IS NULL',
            [$influencerId],
        );

        if ($row === false) {
            return [null, null];
        }

        $email = is_string($row['email'] ?? null) && $row['email'] !== '' ? $row['email'] : null;
        $name  = is_string($row['name'] ?? null) && $row['name'] !== '' ? $row['name'] : null;

        return [$name, $email];
    }

    /**
     * El vídeo en la web, que es el mismo enlace que comparte la app: abierto
     * desde el móvil ofrece la app, y desde un ordenador se ve igual. Un enlace
     * `goveo://` no valdría — en un correo abierto en el escritorio no abre nada.
     *
     * El segmento depende de la categoría, como en `feedSegmentForCategory` de
     * la app y en `shareGeoStory` de la web.
     */
    private function videoUrl(GeoStory $story): string
    {
        $slug = null;

        if ($story->getCategoryId() !== null) {
            $found = $this->db->fetchOne('SELECT slug FROM categories WHERE id = ?', [$story->getCategoryId()]);
            $slug  = is_string($found) ? $found : null;
        }

        $segment = match ($slug) {
            'events'                     => 'e',
            'news'                       => 'g',
            'place', 'nature', 'culture' => 't',
            default                      => 'ol',
        };

        return sprintf('%s/%s/%s', rtrim($this->webUrl, '/'), $segment, $story->getId());
    }

    /** @param callable(): \App\Shared\Application\Mail\MailContent $content */
    private function send(?string $to, string $kind, string $subjectId, callable $content): void
    {
        if ($to === null) {
            // Ni excepción ni silencio: no hay nada que arreglar en el momento,
            // pero sí hay que poder saber después a quién no se avisó.
            $this->logger->warning('Decisión sin avisar: no hay dirección. {kind} {id}', [
                'kind' => $kind,
                'id'   => $subjectId,
            ]);

            return;
        }

        try {
            $mail = $content();

            $this->mailer->send(
                (new Email())
                    // El nombre visible va aquí y no en `EMAIL_FROM`: un valor
                    // con espacios hay que entrecomillarlo en el `.env`, y hay
                    // paneles de despliegue que quitan esas comillas al guardar.
                    ->from(new Address($this->fromAddress, 'Goveo'))
                    ->to($to)
                    ->subject($mail->subject)
                    ->text($mail->text)
                    ->html($mail->html),
            );
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo avisar de la decisión: {message}', [
                'message' => $e->getMessage(),
                'kind'    => $kind,
                'id'      => $subjectId,
            ]);
        }
    }
}
