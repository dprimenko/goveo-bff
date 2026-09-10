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
 * Avisa por correo de que hay algo nuevo que revisar.
 *
 * Sin esto, la cola del panel sólo se vacía si alguien se acuerda de mirarla, y
 * lo que espera ahí no es cualquier cosa: un negocio recién dado de alta —que ha
 * pagado— no sale en la app hasta que se valida.
 *
 * **Es un correo interno**, así que va en texto plano y sin adornos: un resumen
 * de qué ha llegado y el enlace para abrirlo. No lleva botones de aprobar ni
 * rechazar; decidir se hace mirando la ficha, no desde la bandeja de entrada.
 *
 * **No lanza nunca.** Que falle el aviso no puede tumbar un alta ya cobrada ni
 * el webhook de Bunny; se registra y se sigue.
 */
final class ReviewQueueNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        private readonly string $fromAddress,
        /** A dónde se avisa. Vacío = no se avisa, que es lo que se quiere en local. */
        private readonly string $reviewAddress,
        /** Para poder enlazar la ficha en el panel. */
        private readonly string $backofficeUrl,
    ) {}

    public function businessPendingReview(Business $business): void
    {
        $meta    = $business->getMeta() ?? [];
        $billing = $meta['billing'] ?? [];

        $this->send(
            sprintf('Nuevo negocio por validar: %s', $business->getName() ?? $business->getSlug()),
            [
                'Ha entrado un negocio nuevo en la cola de validación.',
                '',
                sprintf('Negocio:   %s', $business->getName() ?? '(sin nombre)'),
                sprintf('Enlace:    %s', $business->getSlug()),
                sprintf('Dirección: %s', $meta['address'] ?? '—'),
                sprintf('Teléfono:  %s', $meta['public_phone'] ?? ($billing['phone'] ?? '—')),
                sprintf('Empresa:   %s', $billing['company_name'] ?? '—'),
                sprintf('NIF/CIF:   %s', $billing['tax_id'] ?? '—'),
                sprintf('Correo:    %s', $billing['email'] ?? '—'),
                '',
                sprintf('Revisar: %s/negocios/%s', rtrim($this->backofficeUrl, '/'), $business->getId()),
            ],
        );
    }

    /**
     * Un vídeo llega a la cola cuando **queda listo**, y a eso se llega por dos
     * caminos: el webhook de Bunny y la reconciliación que hace el BFF cuando su
     * dueño abre el perfil y pregunta el estado. Al vivir el aviso sólo en el
     * primero, los vídeos que se enteraban por el segundo no avisaban a nadie.
     *
     * Las condiciones viven aquí y no en quien llama, para que añadir un tercer
     * camino no vuelva a dejarse el aviso por el camino.
     */
    public function geoStoryPendingReview(GeoStory $story): void
    {
        // Sin codificar no hay nada que mirar, y validado ya no está en la cola
        // —es el caso de los que sube el propio panel—.
        if ($story->getStatus() !== GeoStory::STATUS_READY || $story->isVerified()) {
            return;
        }

        $this->send(
            sprintf('Nuevo vídeo por validar: %s', $story->getTitle() ?? '(sin título)'),
            [
                'Hay un vídeo nuevo esperando en la cola.',
                '',
                sprintf('Título: %s', $story->getTitle() ?? '(sin título)'),
                sprintf('De:     %s', $this->ownerName($story) ?? '—'),
                sprintf('Vídeo:  %s', $story->getUrl()),
                '',
                sprintf('Revisar: %s/videos', rtrim($this->backofficeUrl, '/')),
            ],
        );
    }

    /**
     * De quién es el vídeo. Se resuelve aquí y no en quien avisa: el webhook de
     * Bunny no tiene por qué saber de negocios ni de influencers.
     */
    private function ownerName(GeoStory $story): ?string
    {
        $businessId = $story->getBusinessId();
        if ($businessId !== null) {
            return $this->db->fetchOne('SELECT name FROM business WHERE id = ?', [$businessId]) ?: null;
        }

        $influencerId = $story->getInfluencerId();
        if ($influencerId !== null) {
            return $this->db->fetchOne('SELECT name FROM influencers WHERE id = ?', [$influencerId]) ?: null;
        }

        return null;
    }

    /** @param string[] $lines */
    private function send(string $subject, array $lines): void
    {
        if ($this->reviewAddress === '') {
            return;
        }

        try {
            $this->mailer->send(
                (new Email())
                    ->from(new Address($this->fromAddress, 'Goveo'))
                    ->to($this->reviewAddress)
                    ->subject($subject)
                    ->text(implode("\n", $lines) . "\n"),
            );
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo avisar de la cola de revisión: {message}', [
                'message' => $e->getMessage(),
                'subject' => $subject,
            ]);
        }
    }
}
