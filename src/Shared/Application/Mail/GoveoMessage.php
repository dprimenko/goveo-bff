<?php

declare(strict_types=1);

namespace App\Shared\Application\Mail;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * El sobre de todo el correo que sale de aquí: remitente, destinatario y asunto.
 *
 * Existe por un fallo que sólo se ve en la bandeja de entrada de otro. El asunto
 * «✅ Tu alta en GOVEO está aprobada» lleva caracteres no ASCII, así que Symfony
 * codifica esas palabras sueltas (RFC 2047) y **pliega la cabecera** cuando pasa
 * de 76 columnas:
 *
 *     Subject: =?utf-8?Q?=E2=9C=85?= Tu alta en GOVEO =?utf-8?Q?est=C3=A1?=
 *      aprobada
 *
 * El plegado cae justo detrás de «está». Al desplegarla, la línea siguiente
 * empieza por un espacio que **hay** que conservar… y hay clientes que no lo
 * conservan: quitan el salto y juntan las dos partes. El usuario lee
 * «estáaprobada».
 *
 * La cabecera se deja **sin plegar** (hasta el límite de 998 octetos que permite
 * SMTP), que es lo único que no depende de la buena voluntad del cliente de
 * correo. Nuestros asuntos son de una línea de sobra.
 *
 * Y ya que se centraliza: el **nombre visible va aquí y no en `EMAIL_FROM`**. Un
 * valor con espacios obliga a entrecomillarlo en el `.env`, y hay paneles de
 * despliegue que quitan esas comillas al guardar; el resultado es un fichero que
 * Symfony no puede leer y una aplicación que no arranca.
 */
final class GoveoMessage
{
    private const FROM_NAME = 'Goveo';

    /** @param string|string[] $to */
    public static function create(string $fromAddress, string|array $to, string $subject): Email
    {
        $message = (new Email())
            ->from(new Address($fromAddress, self::FROM_NAME))
            ->subject($subject);

        foreach ((array) $to as $recipient) {
            $message->addTo($recipient);
        }

        $message->getHeaders()->get('Subject')?->setMaxLineLength(998);

        return $message;
    }

    /** El caso normal: un correo ya redactado, en sus dos versiones. */
    public static function from(string $fromAddress, string|array $to, MailContent $mail): Email
    {
        return self::create($fromAddress, $to, $mail->subject)
            ->text($mail->text)
            ->html($mail->html);
    }
}
