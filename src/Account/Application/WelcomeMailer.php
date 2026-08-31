<?php

declare(strict_types=1);

namespace App\Account\Application;

use App\Account\Domain\PasswordSetupToken;
use App\Account\Domain\PasswordSetupTokenRepository;
use App\Business\Domain\Business;
use App\Auth\Infrastructure\Service\KeycloakService;
use App\Business\Domain\BusinessRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * Correo de bienvenida tras el alta de un negocio.
 *
 * Se manda cuando el negocio ya está pagado (o es gratuito), que es el momento
 * en que el dueño necesita entrar. Antes no: quien abandona en la pasarela no
 * debería recibir un «bienvenido».
 *
 * Qué cuenta, y por qué sólo eso:
 *  - **Crear la contraseña**, que es lo único que el usuario tiene que hacer.
 *    Va como acción única y destacada; competir con otros enlaces sólo baja la
 *    probabilidad de que la cree.
 *  - **Que la ficha está pendiente de validación**, dicho aquí y no descubierto
 *    después: es la pregunta que llega a soporte si no se avisa.
 *  - **Qué puede ir haciendo mientras**, para que la espera no sea tiempo
 *    muerto.
 *
 * No lleva tarifa ni importes: de eso ya se encarga el recibo de Stripe, y
 * repetirlo aquí invita a discutir el cobro en el correo equivocado.
 */
final class WelcomeMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly PasswordSetupTokenRepository $tokens,
        private readonly BusinessRepository $businesses,
        private readonly KeycloakService $keycloak,
        private readonly LoggerInterface $logger,
        private readonly string $fromAddress,
        private readonly string $webUrl,
    ) {}

    /**
     * Manda la bienvenida. **No lanza**: que falle el correo no puede tumbar el
     * webhook de Stripe ni deshacer un alta ya cobrada; se registra y se puede
     * reenviar.
     */
    public function send(Business $business, string $userId, string $email, ?string $ownerName = null): void
    {
        try {
            // A quien ya tiene contraseña no se le manda un correo para crearla:
            // pasa cuando alguien da de alta un segundo negocio con su cuenta, y
            // recibir un enlace de contraseña que no ha pedido inquieta.
            if (!$this->keycloak->hasPendingPasswordSetup($email)) {
                $business->markWelcomeEmailSent();
                $this->businesses->save($business);

                return;
            }

            ['token' => $token, 'plain' => $plain] = PasswordSetupToken::issue(
                Uuid::v4()->toRfc4122(),
                $userId,
            );
            $this->tokens->save($token);

            $link = sprintf('%s/bienvenida?token=%s', rtrim($this->webUrl, '/'), $plain);

            $message = (new Email())
                ->from($this->fromAddress)
                ->to($email)
                ->subject(sprintf('%s ya está en Goveo — crea tu contraseña', $business->getName()))
                ->text($this->text($business, $link, $ownerName))
                ->html($this->html($business, $link, $ownerName));

            $this->mailer->send($message);

            // Se marca sólo si el envío no lanzó: es lo que permite listar
            // después a quién no le llegó y reintentarlo.
            $business->markWelcomeEmailSent();
            $this->businesses->save($business);
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo enviar la bienvenida: {message}', [
                'message'  => $e->getMessage(),
                'business' => $business->getId(),
                'email'    => $email,
            ]);
        }
    }

    private function greeting(?string $ownerName): string
    {
        return $ownerName !== null && trim($ownerName) !== ''
            ? sprintf('Hola %s,', trim($ownerName))
            : 'Hola,';
    }

    private function text(Business $business, string $link, ?string $ownerName): string
    {
        return <<<TEXT
        {$this->greeting($ownerName)}

        Ya hemos creado la ficha de {$business->getName()} en Goveo.

        Crea tu contraseña para entrar:
        {$link}

        El enlace caduca en 7 días y sólo se puede usar una vez.

        Qué pasa ahora
        Nuestro equipo revisará tu ficha antes de publicarla. Mientras tanto ya
        puedes entrar y completarla: fotos, descripción, horarios y productos.
        En cuanto la validemos, tu negocio aparecerá en el mapa y en las
        búsquedas de tu zona.

        Si no has sido tú, ignora este correo: sin crear la contraseña nadie
        puede entrar en la cuenta.

        El equipo de Goveo
        TEXT;
    }

    private function html(Business $business, string $link, ?string $ownerName): string
    {
        $name     = htmlspecialchars($business->getName(), ENT_QUOTES, 'UTF-8');
        $greeting = htmlspecialchars($this->greeting($ownerName), ENT_QUOTES, 'UTF-8');
        $href     = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

        // HTML deliberadamente simple y con estilos en línea: los clientes de
        // correo ignoran las hojas de estilo y buena parte del CSS moderno.
        return <<<HTML
        <!doctype html>
        <html lang="es">
        <body style="margin:0;padding:0;background:#0a0a0a;font-family:Helvetica,Arial,sans-serif;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0a;padding:32px 16px;">
            <tr><td align="center">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#141414;border-radius:16px;padding:32px;">
                <tr><td>
                  <p style="margin:0 0 24px;color:#e98027;font-size:13px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;">Goveo</p>

                  <h1 style="margin:0 0 16px;color:#ffffff;font-size:24px;line-height:1.25;">
                    {$name} ya está en Goveo
                  </h1>

                  <p style="margin:0 0 24px;color:#c8c8c8;font-size:15px;line-height:1.6;">
                    {$greeting} hemos creado la ficha de tu negocio. Crea tu contraseña para entrar.
                  </p>

                  <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
                    <tr><td style="background:#e98027;border-radius:12px;">
                      <a href="{$href}" style="display:inline-block;padding:16px 32px;color:#ffffff;font-size:16px;font-weight:bold;text-decoration:none;">
                        Crear mi contraseña
                      </a>
                    </td></tr>
                  </table>

                  <p style="margin:0 0 32px;color:#8a8a8a;font-size:13px;line-height:1.5;">
                    El enlace caduca en 7 días y sólo se puede usar una vez.
                  </p>

                  <div style="height:1px;background:#262626;margin:0 0 24px;"></div>

                  <h2 style="margin:0 0 12px;color:#ffffff;font-size:16px;">Qué pasa ahora</h2>
                  <p style="margin:0 0 12px;color:#c8c8c8;font-size:15px;line-height:1.6;">
                    Nuestro equipo revisará tu ficha antes de publicarla. Mientras tanto ya puedes
                    entrar y completarla: fotos, descripción, horarios y productos.
                  </p>
                  <p style="margin:0 0 32px;color:#c8c8c8;font-size:15px;line-height:1.6;">
                    En cuanto la validemos, tu negocio aparecerá en el mapa y en las búsquedas de tu zona.
                  </p>

                  <p style="margin:0;color:#6a6a6a;font-size:12px;line-height:1.5;">
                    Si no has sido tú, ignora este correo: sin crear la contraseña nadie puede entrar
                    en la cuenta.
                  </p>
                </td></tr>
              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }
}
