<?php

declare(strict_types=1);

namespace App\Account\Application;

use App\Shared\Application\Mail\GoveoEmailLayout as L;
use App\Shared\Application\Mail\MailContent;

/**
 * El correo de bienvenida del alta de un negocio, en sus **dos versiones**.
 *
 * La diferencia no es de estilo, es de a quién se le escribe:
 *
 *  - **Cuenta nueva** (alta desde la web): no tiene contraseña todavía, así que
 *    el correo se reduce a crearla. Es la acción única: competir con otros
 *    enlaces sólo baja la probabilidad de que la cree, y sin contraseña no
 *    puede hacer nada más.
 *  - **Cuenta que ya existía** (alta **desde la app**, o segundo negocio): ya
 *    entró con su cuenta y el negocio quedó colgado de ella. Mandarle un enlace
 *    para crear una contraseña que ya tiene no significa nada y encima inquieta,
 *    así que se le dice dónde está el negocio y qué le queda por hacer.
 *
 * En las dos: que la ficha **está pendiente de validación**, dicho aquí y no
 * descubierto después —es la pregunta que llega a soporte si no se avisa—, y qué
 * puede ir haciendo mientras. **No lleva importes**: de eso se encarga el recibo
 * de Stripe, y repetirlos aquí invita a discutir el cobro en el correo
 * equivocado.
 *
 * Separado del envío ([`WelcomeMailer`]) para poder leerlo y previsualizarlo sin
 * Keycloak ni base de datos, como el resto del correo a clientes.
 */
final class WelcomeMessages
{
    /**
     * @param string|null $passwordLink Enlace de creación de contraseña, o
     *                                  `null` si la cuenta ya tenía una.
     * @param string      $accountUrl   Enlace que abre la app.
     */
    public static function create(
        string $businessName,
        ?string $passwordLink,
        ?string $ownerName,
        string $accountUrl,
    ): MailContent {
        $hi   = $ownerName !== null && trim($ownerName) !== ''
            ? sprintf('Hola %s,', trim($ownerName))
            : 'Hola,';
        $name = L::strong($businessName);

        $next = L::card([
            '🔎&nbsp; <strong>Nuestro equipo revisará tu ficha antes de publicarla.</strong> En cuanto la '
                . 'validemos, tu negocio aparecerá en el mapa y en las búsquedas de tu zona, y te avisamos '
                . 'por correo.',
            '📋&nbsp; <strong>Mientras tanto ya puedes completarla:</strong> fotos, descripción, horarios y '
                . 'productos. Cuanto más completa esté, mejor sale en la revisión.',
        ]);

        if ($passwordLink === null) {
            $html = L::paragraph(L::esc($hi))
                . L::paragraph('Ya hemos creado la ficha de ' . $name . ' en Goveo y la hemos colgado de '
                    . 'tu cuenta: entra en la app y la verás en <strong>Mi cuenta → Mis negocios</strong>.')
                . L::button('Entrar en mi cuenta', $accountUrl)
                . L::paragraph('Qué pasa ahora:', '0 0 8px')
                . $next
                . L::signoff();

            $text = <<<TEXT
            {$hi}

            Ya hemos creado la ficha de {$businessName} en Goveo y la hemos
            colgado de tu cuenta: entra en la app y la verás en Mi cuenta → Mis
            negocios.

            Entrar en mi cuenta:
            {$accountUrl}

            Qué pasa ahora:

            - Nuestro equipo revisará tu ficha antes de publicarla. En cuanto la
              validemos, tu negocio aparecerá en el mapa y en las búsquedas de
              tu zona, y te avisamos por correo.
            - Mientras tanto ya puedes completarla: fotos, descripción, horarios
              y productos.

            Un saludo,
            Equipo GOVEO
            TEXT;

            return new MailContent(
                sprintf('%s ya está en Goveo — termina de configurarlo', $businessName),
                L::render(
                    sprintf('%s ya está en Goveo, colgado de tu cuenta.', $businessName),
                    sprintf('%s ya está en Goveo', $businessName),
                    sprintf('%s ya está en Goveo', L::esc($businessName)),
                    $html,
                ),
                $text,
            );
        }

        $html = L::paragraph(L::esc($hi))
            . L::paragraph('Ya hemos creado la ficha de ' . $name . ' en Goveo. Crea tu contraseña para entrar.')
            . L::button('Crear mi contraseña', $passwordLink)
            . L::fineprint('El enlace caduca en 7 días y sólo se puede usar una vez.')
            . L::paragraph('Qué pasa ahora:', '0 0 8px')
            . $next
            . L::paragraph('Si no has sido tú, ignora este correo: sin crear la contraseña nadie puede '
                . 'entrar en la cuenta.')
            . L::signoff();

        $text = <<<TEXT
        {$hi}

        Ya hemos creado la ficha de {$businessName} en Goveo.

        Crea tu contraseña para entrar:
        {$passwordLink}

        El enlace caduca en 7 días y sólo se puede usar una vez.

        Qué pasa ahora:

        - Nuestro equipo revisará tu ficha antes de publicarla. En cuanto la
          validemos, tu negocio aparecerá en el mapa y en las búsquedas de tu
          zona, y te avisamos por correo.
        - Mientras tanto ya puedes completarla: fotos, descripción, horarios y
          productos.

        Si no has sido tú, ignora este correo: sin crear la contraseña nadie
        puede entrar en la cuenta.

        Un saludo,
        Equipo GOVEO
        TEXT;

        return new MailContent(
            sprintf('%s ya está en Goveo — crea tu contraseña', $businessName),
            L::render(
                sprintf('%s ya está en Goveo. Crea tu contraseña para entrar.', $businessName),
                sprintf('%s ya está en Goveo', $businessName),
                sprintf('%s ya está en Goveo', L::esc($businessName)),
                $html,
            ),
            $text,
        );
    }
}
