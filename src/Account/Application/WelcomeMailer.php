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
 *
 * **La redacción vive en [`WelcomeMessages`]** y la caja en
 * [`GoveoEmailLayout`], como el resto del correo a clientes: cuando cada correo
 * traía su propio HTML, éste se quedó en oscuro y los del panel salieron en
 * claro, y dos mensajes seguidos del mismo remitente parecían de sitios
 * distintos. Aquí queda lo que sí es de esta clase: cuándo se manda, a quién, y
 * con qué enlace de contraseña.
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
        /** Enlace que abre la app, para quien ya tiene cuenta. */
        private readonly string $appUrl,
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

            $mail = WelcomeMessages::create(
                $business->getName() ?? 'Tu negocio',
                $link,
                $ownerName,
                $this->appUrl,
            );

            $message = (new Email())
                // El nombre visible va aquí y no en `EMAIL_FROM` a propósito:
                // un valor con espacios obliga a entrecomillarlo en el `.env`, y
                // hay paneles de despliegue que quitan esas comillas al
                // guardarlo. El resultado es un fichero que Symfony no puede
                // leer y una aplicación que no arranca. La variable se queda con
                // la dirección a secas, que nunca lleva espacios.
                ->from(new Address($this->fromAddress, 'Goveo'))
                ->to($email)
                ->subject($mail->subject)
                ->text($mail->text)
                ->html($mail->html);

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
}
