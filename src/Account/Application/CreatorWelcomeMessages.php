<?php

declare(strict_types=1);

namespace App\Account\Application;

use App\Shared\Application\Mail\GoveoEmailLayout as L;
use App\Shared\Application\Mail\MailContent;

/**
 * La bienvenida del alta de un creador, en las mismas dos versiones que la del
 * negocio (ver [`WelcomeMessages`]): con cuenta nueva, crear la contraseña; con
 * la que ya tenía, dónde está su perfil. En las dos, que **está pendiente de
 * validación** y que le avisaremos al aprobarlo.
 */
final class CreatorWelcomeMessages
{
    /**
     * @param string|null $passwordLink Enlace de creación de contraseña, o
     *                                  `null` si la cuenta ya tenía una.
     */
    public static function create(string $creatorName, ?string $passwordLink, string $accountUrl): MailContent
    {
        $hi = trim($creatorName) !== '' ? sprintf('Hola %s,', trim($creatorName)) : 'Hola,';

        $next = L::card([
            '🔎&nbsp; <strong>Nuestro equipo revisará tu perfil antes de publicarlo.</strong> En cuanto lo '
                . 'validemos, aparecerás en Goveo y te avisamos por correo.',
            '🎬&nbsp; <strong>Mientras tanto ya puedes ir subiendo tus vídeos</strong> desde la app: '
                . 'quedan guardados y se revisan a la vez.',
        ]);
        $nextText = <<<TEXT
        - Nuestro equipo revisará tu perfil antes de publicarlo. En cuanto lo
          validemos, aparecerás en Goveo y te avisamos por correo.
        - Mientras tanto ya puedes ir subiendo tus vídeos desde la app: quedan
          guardados y se revisan a la vez.
        TEXT;

        if ($passwordLink === null) {
            $html = L::paragraph(L::esc($hi))
                . L::paragraph('Ya hemos creado tu perfil de creador en Goveo y lo hemos colgado de tu cuenta.')
                . L::button('Entrar en mi cuenta', $accountUrl)
                . L::paragraph('Qué pasa ahora:', '0 0 8px')
                . $next
                . L::signoff();

            $text = <<<TEXT
            {$hi}

            Ya hemos creado tu perfil de creador en Goveo y lo hemos colgado de
            tu cuenta.

            Entrar en mi cuenta:
            {$accountUrl}

            Qué pasa ahora:

            {$nextText}

            Un saludo,
            Equipo GOVEO
            TEXT;

            return new MailContent(
                'Tu perfil de creador ya está en Goveo',
                L::render(
                    'Tu perfil de creador ya está en Goveo, pendiente de validación.',
                    'Tu perfil de creador ya está en Goveo',
                    'Tu perfil de creador ya está en Goveo',
                    $html,
                ),
                $text,
            );
        }

        $html = L::paragraph(L::esc($hi))
            . L::paragraph('Ya hemos creado tu perfil de creador en Goveo. Crea tu contraseña para entrar.')
            . L::button('Crear mi contraseña', $passwordLink)
            . L::fineprint('El enlace caduca en 7 días y sólo se puede usar una vez.')
            . L::paragraph('Qué pasa ahora:', '0 0 8px')
            . $next
            . L::paragraph('Si no has sido tú, ignora este correo: sin crear la contraseña nadie puede '
                . 'entrar en la cuenta.')
            . L::signoff();

        $text = <<<TEXT
        {$hi}

        Ya hemos creado tu perfil de creador en Goveo.

        Crea tu contraseña para entrar:
        {$passwordLink}

        El enlace caduca en 7 días y sólo se puede usar una vez.

        Qué pasa ahora:

        {$nextText}

        Si no has sido tú, ignora este correo: sin crear la contraseña nadie
        puede entrar en la cuenta.

        Un saludo,
        Equipo GOVEO
        TEXT;

        return new MailContent(
            'Tu perfil de creador ya está en Goveo — crea tu contraseña',
            L::render(
                'Tu perfil de creador ya está en Goveo. Crea tu contraseña para entrar.',
                'Tu perfil de creador ya está en Goveo',
                'Tu perfil de creador ya está en Goveo',
                $html,
            ),
            $text,
        );
    }
}
