<?php

declare(strict_types=1);

namespace App\Backoffice\Application;

use App\Business\Domain\Business;
use App\Influencers\Domain\Influencer;
use App\GeoStories\Domain\GeoStory;
use App\Loyalty\Application\LoyaltyStatus;
use App\Moderation\Domain\ContentReport;
use App\Moderation\Domain\ReportReason;
use App\Moderation\Domain\ReportTarget;
use App\Shared\Application\Mail\GoveoMessage;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Avisa por correo de que hay algo nuevo que revisar.
 *
 * Sin esto, la cola del panel sólo se vacía si alguien se acuerda de mirarla, y
 * lo que espera ahí no es cualquier cosa: un negocio recién dado de alta —que ha
 * pagado— no sale en la app hasta que se valida.
 *
 * **Es un correo interno**, así que va sin adornos: un resumen de qué ha llegado
 * y el enlace para abrirlo. No lleva botones de aprobar ni rechazar; decidir se
 * hace mirando la ficha, no desde la bandeja de entrada.
 *
 * Va en las dos versiones —texto y un HTML mínimo— por una sola razón: **que el
 * enlace se pueda pulsar**. En texto plano hay clientes que lo autoenlazan y
 * otros que lo dejan como texto muerto, y entonces revisar un vídeo empieza por
 * copiar una URL a mano.
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

    /** Un creador dado de alta desde la web o la app, por validar (06-10-2026). */
    public function influencerPendingReview(Influencer $influencer, string $email): void
    {
        $meta = $influencer->getMeta() ?? [];

        $this->send(
            sprintf('Nuevo creador por validar: %s', $influencer->getName()),
            [
                'Ha entrado un creador nuevo en la cola de validación.',
                '',
                sprintf('Nombre:    %s', $influencer->getName()),
                sprintf('Usuario:   @%s', $influencer->getUsername()),
                sprintf('Instagram: %s', isset($meta['instagram']) ? 'https://instagram.com/' . $meta['instagram'] : '—'),
                sprintf('TikTok:    %s', isset($meta['tiktok']) ? 'https://tiktok.com/@' . $meta['tiktok'] : '—'),
                sprintf('Correo:    %s', $email),
                '',
                sprintf('Revisar: %s/influencers?q=%s', rtrim($this->backofficeUrl, '/'), rawurlencode($influencer->getUsername())),
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

        // Sin dirección de revisión no se avisa a nadie, y eso hay que poder
        // saberlo: el síntoma es «no me llega el correo» y desde fuera no se
        // distingue de un fallo del servidor de correo.
        if ($this->reviewAddress === '') {
            $this->logger->warning(
                'Contenido por validar sin avisar: BACKOFFICE_REVIEW_EMAIL está vacío. {id}',
                ['id' => $story->getId()],
            );
        }

        // Vídeo o foto: se publican por el mismo sitio y se revisan en la misma
        // cola, pero llamar «vídeo» a una foto hace dudar de si el aviso es el
        // que toca.
        $que = $story->isImage() ? 'foto' : 'vídeo';

        $this->send(
            sprintf('Nueva %s por validar: %s', $que, $story->getTitle() ?? '(sin título)'),
            [
                sprintf('Hay una %s nueva esperando en la cola.', $que),
                '',
                sprintf('Título: %s', $story->getTitle() ?? '(sin título)'),
                sprintf('De:     %s', $this->ownerName($story) ?? '—'),
                sprintf('%s: %s', ucfirst($que), $story->getUrl()),
                '',
                sprintf('Revisar: %s/videos', rtrim($this->backofficeUrl, '/')),
            ],
        );
    }

    /**
     * Alguien ha denunciado algo, o ha bloqueado a una cuenta (que deja una
     * denuncia con motivo `blocked`: Apple pide que bloquear avise también).
     *
     * El asunto dice cuántas lleva abiertas eso mismo: la quinta denuncia al
     * mismo vídeo no se lee igual que la primera.
     */
    public function contentReported(ContentReport $report, string $label, ?string $ownerName, int $openCount): void
    {
        if ($this->reviewAddress === '') {
            $this->logger->warning(
                'Denuncia sin avisar: BACKOFFICE_REVIEW_EMAIL está vacío. {id}',
                ['id' => $report->getId()],
            );
        }

        $que = match ($report->getTargetType()) {
            ReportTarget::GeoStory   => 'Vídeo',
            ReportTarget::Product    => 'Producto',
            ReportTarget::Business   => 'Negocio',
            ReportTarget::Influencer => 'Influencer',
        };

        $blocked = $report->getReason() === ReportReason::Blocked;

        $this->send(
            sprintf(
                '%s: %s%s',
                $blocked ? 'Cuenta bloqueada por un usuario' : 'Nueva denuncia',
                $label,
                $openCount > 1 ? sprintf(' (%d abiertas)', $openCount) : '',
            ),
            [
                $blocked
                    ? 'Un usuario ha bloqueado esta cuenta. Conviene mirar qué publica.'
                    : 'Un usuario ha denunciado este contenido. Hay que atenderlo en menos de 24 h.',
                '',
                sprintf('%s: %s', str_pad($que, 10), $label),
                sprintf('De:         %s', $ownerName ?? '—'),
                sprintf('Motivo:     %s', $report->getReason()->label()),
                sprintf('Comentario: %s', $report->getComment() ?? '—'),
                sprintf('Abiertas:   %d', $openCount),
                '',
                sprintf('Revisar: %s/denuncias', rtrim($this->backofficeUrl, '/')),
            ],
        );
    }

    /**
     * Un negocio ha tocado su tarjeta de fidelización: los premios, o la ha
     * encendido o apagado.
     *
     * Para poder echarle una mano: lo normal es que ponga los premios y se le
     * olvide encenderla, o que no la tenga en su tarifa y haya que activársela
     * a mano. El correo dice en qué ha quedado y, si no se ve, por qué.
     *
     * @param string[] $changes lo que ha cambiado, ya en frase
     */
    public function loyaltyChanged(Business $business, LoyaltyStatus $status, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $name  = $business->getName() ?? $business->getSlug();
        $state = match (true) {
            $status->isAvailable()  => 'Visible para los clientes.',
            !$status->isEnabled()   => 'No se ve: su tarifa no la incluye. Actívala a mano en el panel si le corresponde.',
            !$status->hasRewards    => 'No se ve: no tiene ningún premio puesto.',
            default                 => 'No se ve: está apagada. Puede encenderla el negocio o el panel.',
        };

        $this->send(
            sprintf('Tarjeta de fidelización: %s', $name),
            [
                sprintf('%s ha cambiado su tarjeta de fidelización.', $name),
                '',
                ...array_map(static fn (string $c) => '· ' . $c, $changes),
                '',
                sprintf('Estado: %s', $state),
                '',
                sprintf('Ficha: %s/negocios/%s', rtrim($this->backofficeUrl, '/'), $business->getId()),
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
                GoveoMessage::create($this->fromAddress, $this->reviewAddress, $subject)
                    ->text(implode("\n", $lines) . "\n")
                    ->html(self::clickable($lines)),
            );
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo avisar de la cola de revisión: {message}', [
                'message' => $e->getMessage(),
                'subject' => $subject,
            ]);
        }
    }

    /**
     * El mismo texto, con los enlaces pulsables.
     *
     * Monoespaciado y en un `<pre>` para que las columnas del resumen (`Negocio:`,
     * `Dirección:`…) sigan cuadrando: es lo que hace que se lea de un vistazo.
     *
     * @param string[] $lines
     */
    private static function clickable(array $lines): string
    {
        $html = array_map(static function (string $line): string {
            $escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

            // Sólo el propio enlace se convierte, no la etiqueta que lo precede.
            return preg_replace(
                '~(https?://\S+)~',
                '<a href="$1">$1</a>',
                $escaped,
            ) ?? $escaped;
        }, $lines);

        return sprintf(
            '<pre style="font:14px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;'
            . 'white-space:pre-wrap;color:#111319;">%s</pre>',
            implode("\n", $html),
        );
    }
}
