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
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * Correo de bienvenida tras el alta de un negocio.
 *
 * Se manda cuando el negocio ya está pagado (o es gratuito), que es el momento
 * en que el dueño necesita entrar. Antes no: quien abandona en la pasarela no
 * debería recibir un «bienvenido».
 *
 * **Va en dos versiones, según de dónde venga el alta.** Quien la hizo desde la
 * web no tiene todavía contraseña, así que lo único que necesita es crearla, y
 * el correo se reduce a eso. Pero quien la hizo **desde la app** ya entró con su
 * cuenta y el negocio quedó colgado de ella: mandarle un enlace para crear una
 * contraseña que ya tiene no significa nada, y encima inquieta. A ése se le
 * cuenta cómo seguir configurando el negocio, que es lo que le queda por hacer.
 *
 * Antes ese segundo caso **no recibía nada**: si la cuenta ya tenía contraseña
 * el correo se saltaba entero, y quien daba de alta desde la app se quedaba sin
 * enterarse de que su ficha estaba pendiente de validación.
 *
 * Qué cuenta, y por qué sólo eso:
 *  - **La acción que toca**, una sola: crear la contraseña, o entrar a
 *    completar la ficha. Competir con otros enlaces sólo baja la probabilidad
 *    de que se haga.
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
            // Sólo hay contraseña que crear si la cuenta nació con este alta.
            // Quien ya la tenía —alta desde la app, o segundo negocio— recibe la
            // otra versión: un enlace para crear algo que ya existe no le dice
            // nada y le hace dudar de si le han tocado la cuenta.
            $needsPassword = $this->keycloak->hasPendingPasswordSetup($email);
            $link          = null;

            if ($needsPassword) {
                ['token' => $token, 'plain' => $plain] = PasswordSetupToken::issue(
                    Uuid::v4()->toRfc4122(),
                    $userId,
                );
                $this->tokens->save($token);

                $link = sprintf('%s/bienvenida?token=%s', rtrim($this->webUrl, '/'), $plain);
            }

            $subject = $needsPassword
                ? sprintf('%s ya está en Goveo — crea tu contraseña', $business->getName())
                : sprintf('%s ya está en Goveo — termina de configurarlo', $business->getName());

            $message = (new Email())
                // El nombre visible va aquí y no en `EMAIL_FROM` a propósito:
                // un valor con espacios obliga a entrecomillarlo en el `.env`, y
                // hay paneles de despliegue que quitan esas comillas al
                // guardarlo. El resultado es un fichero que Symfony no puede
                // leer y una aplicación que no arranca. La variable se queda con
                // la dirección a secas, que nunca lleva espacios.
                ->from(new Address($this->fromAddress, 'Goveo'))
                ->to($email)
                ->subject($subject)
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

    private function text(Business $business, ?string $link, ?string $ownerName): string
    {
        $next = <<<TEXT
        Qué pasa ahora
        Nuestro equipo revisará tu ficha antes de publicarla. Mientras tanto ya
        puedes entrar y completarla: fotos, descripción, horarios y productos.
        En cuanto la validemos, tu negocio aparecerá en el mapa y en las
        búsquedas de tu zona.
        TEXT;

        if ($link === null) {
            return <<<TEXT
            {$this->greeting($ownerName)}

            Ya hemos creado la ficha de {$business->getName()} en Goveo, colgada
            de tu cuenta: entra en la app con ella y la verás en Mi cuenta →
            Gestión de negocios.

            {$next}

            El equipo de Goveo
            TEXT;
        }

        return <<<TEXT
        {$this->greeting($ownerName)}

        Ya hemos creado la ficha de {$business->getName()} en Goveo.

        Crea tu contraseña para entrar:
        {$link}

        El enlace caduca en 7 días y sólo se puede usar una vez.

        {$next}

        Si no has sido tú, ignora este correo: sin crear la contraseña nadie
        puede entrar en la cuenta.

        El equipo de Goveo
        TEXT;
    }

    private function html(Business $business, ?string $link, ?string $ownerName): string
    {
        $name     = htmlspecialchars($business->getName(), ENT_QUOTES, 'UTF-8');
        $greeting = htmlspecialchars($this->greeting($ownerName), ENT_QUOTES, 'UTF-8');

        // Con cuenta ya hecha no hay botón: la acción no es pulsar nada aquí,
        // es abrir la app. Un botón que sólo lleva a una página informativa
        // gasta el sitio de la llamada a la acción sin llevar a ninguna parte.
        if ($link === null) {
            $intro  = sprintf(
                '%s hemos creado la ficha de tu negocio y la hemos colgado de tu cuenta.',
                $greeting,
            );
            $action = <<<HTML
            <p style="margin:0 0 32px;color:#c8c8c8;font-size:15px;line-height:1.6;">
              Entra en la app con tu cuenta y la encontrarás en <strong style="color:#ffffff;">Mi
              cuenta → Gestión de negocios</strong>.
            </p>
            HTML;
            $footer = '';
        } else {
            $href   = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
            $intro  = sprintf(
                '%s hemos creado la ficha de tu negocio. Crea tu contraseña para entrar.',
                $greeting,
            );
            $action = <<<HTML
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
            HTML;
            $footer = <<<HTML
            <p style="margin:0;color:#6a6a6a;font-size:12px;line-height:1.5;">
              Si no has sido tú, ignora este correo: sin crear la contraseña nadie puede entrar
              en la cuenta.
            </p>
            HTML;
        }

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
                    {$intro}
                  </p>

                  {$action}

                  <div style="height:1px;background:#262626;margin:0 0 24px;"></div>

                  <h2 style="margin:0 0 12px;color:#ffffff;font-size:16px;">Qué pasa ahora</h2>
                  <p style="margin:0 0 12px;color:#c8c8c8;font-size:15px;line-height:1.6;">
                    Nuestro equipo revisará tu ficha antes de publicarla. Mientras tanto ya puedes
                    entrar y completarla: fotos, descripción, horarios y productos.
                  </p>
                  <p style="margin:0 0 32px;color:#c8c8c8;font-size:15px;line-height:1.6;">
                    En cuanto la validemos, tu negocio aparecerá en el mapa y en las búsquedas de tu zona.
                  </p>

                  {$footer}
                </td></tr>
              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }
}
