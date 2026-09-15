<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Shared\Application\Mail\GoveoEmailLayout as L;
use App\Shared\Application\Mail\GoveoMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * «Tu alta está guardada, termina el pago cuando puedas.»
 *
 * Sale **al crear el alta**, antes de que nadie haya pagado, y es a propósito:
 * el enlace de Stripe vivía sólo en la pantalla que se acababa de abrir, así que
 * cerrar la pestaña —o que fallara la tarjeta, o querer que pague la gestoría al
 * día siguiente— dejaba el negocio creado y sin forma de retomar el cobro.
 *
 * **No lleva a Stripe directamente**, lleva a una página nuestra: si para
 * cuando la abran ya está pagado, lo dice en vez de ofrecer otra vez el pago.
 * Un botón que cobra dos veces es peor que un botón que falta.
 *
 * Está escrito para que **no moleste a quien paga en el momento**: no dice
 * «te falta pagar», dice que aquí queda el enlace por si acaso.
 */
final class PendingPaymentMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $fromAddress,
        private readonly string $webUrl,
    ) {}

    /** No lanza: un alta ya hecha no puede caerse porque falle un correo. */
    public function send(string $to, string $businessName, string $businessId, ?int $amountCents): void
    {
        $enlace = sprintf('%s/pago/%s', rtrim($this->webUrl, '/'), $businessId);
        $nombre = L::strong($businessName);
        $importe = $amountCents === null
            ? ''
            : sprintf(' (%s)', number_format($amountCents / 100, 2, ',', '.') . ' €');

        $html = L::paragraph('Hola,')
            . L::paragraph('Hemos guardado la ficha de ' . $nombre . ' en GOVEO. Para que quede '
                . 'activa sólo falta completar el pago' . $importe . '.')
            . L::paragraph('Si lo acabas de hacer, ignora este correo: el alta ya está en marcha. '
                . 'Y si se quedó a medias —se cerró la ventana, falló la tarjeta, o prefieres que lo '
                . 'haga otra persona—, este enlace sigue sirviendo:', '0 0 8px')
            . L::button('Completar el pago', $enlace)
            . L::fineprint('El enlace no caduca y se puede compartir con quien vaya a pagar.')
            . L::paragraph('Cualquier duda, escríbenos al <strong>' . L::SUPPORT_PHONE . '</strong> '
                . 'o responde a este correo.')
            . L::signoff();

        $texto = <<<TEXTO
        Hola,

        Hemos guardado la ficha de {$businessName} en GOVEO. Para que quede
        activa sólo falta completar el pago{$importe}.

        Si lo acabas de hacer, ignora este correo. Y si se quedó a medias, este
        enlace sigue sirviendo:

        {$enlace}

        No caduca, y se puede compartir con quien vaya a pagar.

        Un saludo,
        Equipo GOVEO
        TEXTO;

        try {
            $this->mailer->send(
                GoveoMessage::create($this->fromAddress, $to, sprintf('Termina el alta de %s en GOVEO', $businessName))
                    ->text($texto)
                    ->html(L::render(
                        sprintf('Sólo falta completar el pago para activar %s.', $businessName),
                        'Termina el alta de tu negocio',
                        'Ya casi está',
                        $html,
                    )),
            );
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo mandar el aviso de pago pendiente: {message}', [
                'message'  => $e->getMessage(),
                'business' => $businessId,
            ]);
        }
    }
}
