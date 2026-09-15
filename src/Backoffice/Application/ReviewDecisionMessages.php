<?php

declare(strict_types=1);

namespace App\Backoffice\Application;

use App\Shared\Application\Mail\GoveoEmailLayout as L;
use App\Shared\Application\Mail\MailContent;

/**
 * Lo que se le dice a alguien cuando se decide sobre lo que ha mandado:
 * su negocio, su vídeo o su cuenta de creador, aprobado o no.
 *
 * **Sólo redacta**: no sabe a quién se manda ni cómo se envía (eso es
 * [`ReviewDecisionMailer`]). Así la redacción se puede leer entera de un tirón,
 * verse con `goveo:mail:preview` sin tocar la base de datos, y probarse sin
 * SMTP.
 *
 * Las tres reglas de todos estos correos:
 *
 *  - **Se dice la decisión en la primera línea.** Quien abre esto lleva días
 *    esperando; hacerle leer tres párrafos para saber si sí o si no es cruel.
 *  - **Un solo botón**, y el que corresponda al momento: entrar a configurar,
 *    o ver el vídeo publicado. Un negativo no lleva botón — no hay nada que
 *    pulsar, y un botón ahí sólo parece un formulario de reclamación.
 *  - **Un negativo dice cómo volver a intentarlo.** Lo que se rechaza aquí casi
 *    siempre es arreglable (un vídeo mal grabado, una ficha a medias), y sin esa
 *    parte el correo sólo da una puerta cerrada y una respuesta a soporte.
 */
final class ReviewDecisionMessages
{
    /** Lo que puede hacer un negocio recién aprobado, en orden de importancia. */
    public static function businessApproved(string $businessName, string $accountUrl): MailContent
    {
        $name = L::strong($businessName);

        $html = L::paragraph('Hola,')
            . L::paragraph($name . ' ha sido aprobado y ya forma parte de la selección de GOVEO, la '
                . 'Vídeo Smart City de Madrid.')
            . L::paragraph('Tu cuenta ya está activa y puedes empezar ahora mismo, según la categoría '
                . 'que hayas elegido:', '0 0 8px')
            . L::card([
                '🎬&nbsp; <strong>Sube tu primer vídeo (GeoClip)</strong> para aparecer en el mapa de tu '
                    . 'zona. Lo revisamos y te avisamos de su aprobación en cuanto esté visible en el mapa.',
                '🛍️&nbsp; <strong>Añade tus productos o servicios</strong> a tu perfil.',
                '📋&nbsp; <strong>Completa los datos de tu negocio:</strong> horario, dirección, contacto y fotos.',
            ])
            // La aclaración va **dentro** del botón: el enlace abre la app, y
            // quien lea el correo en el ordenador tiene que saberlo antes de
            // pulsar, no después de que no pase nada.
            . L::button('Ir a mi cuenta', $accountUrl, '(Abrir en el móvil)')
            . L::paragraph('Otra cosa que te recomendamos hacer ahora:', '0 0 8px')
            . L::paragraph('📣&nbsp; <strong>Impulsa tu visibilidad consiguiendo seguidores.</strong> Cada '
                . 'seguidor es un cliente muy potencial: verá tus vídeos y novedades antes que nadie. '
                . 'Además, cuantos más seguidores tenga tu perfil, mucha más visibilidad ganas dentro de '
                . 'la plataforma.')
            . L::paragraph('📲&nbsp; <strong>Comparte el enlace de tu perfil</strong> en tus redes y en tu '
                . 'WhatsApp de clientes. Es la forma más rápida de sumar seguidores y empezar a notar el '
                . 'efecto.')
            . L::paragraph('💬&nbsp; <strong>Guarda nuestro contacto (' . L::SUPPORT_PHONE . ')</strong> '
                . 'para cualquier cambio o duda. Respondemos en el día.')
            . L::signoff();

        $phone = L::SUPPORT_PHONE;
        $text  = <<<TEXT
        Hola,

        {$businessName} ha sido aprobado y ya forma parte de la selección de
        GOVEO, la Vídeo Smart City de Madrid.

        Tu cuenta ya está activa y puedes empezar ahora mismo:

        - Sube tu primer vídeo (GeoClip) para aparecer en el mapa de tu zona.
          Lo revisamos y te avisamos de su aprobación en cuanto esté visible en
          el mapa.
        - Añade tus productos o servicios a tu perfil.
        - Completa los datos de tu negocio: horario, dirección, contacto y fotos.

        Ir a mi cuenta (abrir en el móvil):
        {$accountUrl}

        Otra cosa que te recomendamos hacer ahora:

        - Impulsa tu visibilidad consiguiendo seguidores. Cada seguidor es un
          cliente muy potencial: verá tus vídeos y novedades antes que nadie, y
          cuantos más tenga tu perfil, más visibilidad ganas en la plataforma.
        - Comparte el enlace de tu perfil en tus redes y en tu WhatsApp de
          clientes: es la forma más rápida de sumar seguidores.
        - Guarda nuestro contacto ({$phone}) para cualquier cambio o duda.
          Respondemos en el día.

        Un saludo,
        Equipo GOVEO
        TEXT;

        return new MailContent(
            '✅ Tu alta en GOVEO está aprobada',
            L::render(
                sprintf('%s ya forma parte de la selección de GOVEO. Tu cuenta está activa.', $businessName),
                'Tu alta en GOVEO está aprobada',
                '¡Enhorabuena! 🎉',
                $html,
            ),
            $text,
        );
    }

    /**
     * El «no» de un negocio. Es el correo más delicado de los seis: al otro
     * lado hay alguien que ha rellenado un formulario entero y, si su tarifa
     * era de pago, que ha pagado. No lleva botón y sí dice qué puede hacer.
     */
    public static function businessRejected(string $businessName): MailContent
    {
        $name = L::strong($businessName);

        $html = L::paragraph('Hola,')
            . L::paragraph('Antes de nada, gracias por registrar ' . $name . ' en GOVEO y por el tiempo '
                . 'que le has dedicado. De verdad que valoramos que quieras formar parte de este proyecto.')
            . L::paragraph('Hemos revisado tu solicitud y, en esta ocasión, no podemos darle el alta en '
                . 'nuestra selección. GOVEO funciona como una selección cuidada de negocios por zona, y '
                . 'ahora mismo tu perfil no encaja con el tipo de negocio y de contenido que estamos '
                . 'incorporando a la red en esta etapa.')
            . L::paragraph('Esto no es una puerta cerrada:', '0 0 8px')
            . L::card([
                '🗂️&nbsp; <strong>Guardamos tus datos</strong> y, si más adelante abrimos la selección a tu '
                    . 'categoría o a tu zona, te avisaremos los primeros.',
                '💬&nbsp; <strong>Si el motivo es el contenido,</strong> escríbenos y te contamos qué tipo de '
                    . 'vídeo y de perfil funciona en GOVEO. Muchos negocios entran a la segunda con un '
                    . 'contenido mejor preparado, y podemos ayudarte con ello.',
            ])
            . L::paragraph('Para cualquier duda, o si crees que ha habido un error en la revisión, '
                . 'respóndenos a este email o escríbenos por WhatsApp al <strong>' . L::SUPPORT_PHONE
                . '</strong>. Respondemos en el día.')
            . L::signoff('Gracias de nuevo por contar con nosotros.');

        $phone = L::SUPPORT_PHONE;
        $text  = <<<TEXT
        Hola,

        Antes de nada, gracias por registrar {$businessName} en GOVEO y por el
        tiempo que le has dedicado.

        Hemos revisado tu solicitud y, en esta ocasión, no podemos darle el alta
        en nuestra selección. GOVEO funciona como una selección cuidada de
        negocios por zona, y ahora mismo tu perfil no encaja con el tipo de
        negocio y de contenido que estamos incorporando en esta etapa.

        Esto no es una puerta cerrada:

        - Guardamos tus datos y, si más adelante abrimos la selección a tu
          categoría o a tu zona, te avisaremos los primeros.
        - Si el motivo es el contenido, escríbenos y te contamos qué tipo de
          vídeo y de perfil funciona en GOVEO.

        Para cualquier duda, o si crees que ha habido un error en la revisión,
        responde a este email o escríbenos por WhatsApp al {$phone}.

        Gracias de nuevo por contar con nosotros.

        Un saludo,
        Equipo GOVEO
        TEXT;

        return new MailContent(
            'Sobre tu solicitud de alta en GOVEO',
            L::render(
                'Gracias por tu interés en GOVEO. Te contamos el estado de tu solicitud.',
                'Sobre tu solicitud de alta en GOVEO',
                'Gracias por tu interés en GOVEO',
                $html,
            ),
            $text,
        );
    }

    /**
     * Vídeo publicado. El botón lleva al vídeo **en el mapa** y no al perfil:
     * es lo que se quiere comprobar y lo que se va a compartir después.
     */
    public static function videoApproved(?string $videoTitle, string $videoUrl): MailContent
    {
        // Con título se nombra: quien sube varios seguidos necesita saber cuál
        // de ellos ha entrado.
        $what = $videoTitle === null || trim($videoTitle) === ''
            ? 'Tu vídeo'
            : 'Tu vídeo ' . L::strong(trim($videoTitle));

        $html = L::paragraph('Hola,')
            . L::paragraph($what . ' ha pasado la selección, ha sido aprobado y <strong>ya está publicado '
                . 'en el mapa de GOVEO</strong>. Desde hoy es visible para todo el que explore tu zona.')
            . L::button('Ver mi vídeo en el mapa', $videoUrl)
            . L::paragraph('Lo que te recomendamos hacer ahora:', '0 0 8px')
            . L::card([
                '📲&nbsp; <strong>Comparte el enlace de tu vídeo y de tu perfil</strong> en tus redes y en tu '
                    . 'WhatsApp de clientes. Cuanta más gente lo vea, antes empiezas a notar el efecto. Y '
                    . 'recuerda: cuantos más seguidores tengas, más visibilidad tendrás.',
            ])
            . L::signoff();

        $plainWhat = $videoTitle === null || trim($videoTitle) === ''
            ? 'Tu vídeo'
            : sprintf('Tu vídeo «%s»', trim($videoTitle));

        $text = <<<TEXT
        Hola,

        {$plainWhat} ha pasado la selección, ha sido aprobado y ya está
        publicado en el mapa de GOVEO. Desde hoy es visible para todo el que
        explore tu zona.

        Ver mi vídeo en el mapa:
        {$videoUrl}

        Te recomendamos compartir el enlace de tu vídeo y de tu perfil en tus
        redes y en tu WhatsApp de clientes: cuantos más seguidores tengas, más
        visibilidad tendrás.

        Un saludo,
        Equipo GOVEO
        TEXT;

        return new MailContent(
            '🎬 Tu vídeo ha sido aprobado en GOVEO',
            L::render(
                'Tu vídeo ha pasado la selección y ya está publicado en el mapa de GOVEO.',
                'Tu vídeo ha sido aprobado en GOVEO',
                '¡Buenas noticias! 🎬',
                $html,
            ),
            $text,
        );
    }

    /**
     * El «no» de un vídeo, que casi siempre se arregla volviendo a grabar. Por
     * eso lo que ocupa el correo son los tres factores que hacen que un vídeo
     * entre, y no la negativa.
     */
    public static function videoRejected(?string $videoTitle): MailContent
    {
        $what = $videoTitle === null || trim($videoTitle) === ''
            ? 'el vídeo que subiste'
            : 'tu vídeo ' . L::strong(trim($videoTitle));

        $html = L::paragraph('Hola,')
            . L::paragraph('Hemos revisado ' . $what . ' y, en esta ocasión, no ha pasado la selección, '
                . 'así que aún no está publicado en el mapa.')
            . L::paragraph('Recuerda que únicamente aprobamos vídeos de valor, de buena calidad y con '
                . 'contexto. Por eso te invitamos a que lo revises, hagas las modificaciones y vuelvas a '
                . 'publicarlo, teniendo en cuenta estos factores:', '0 0 8px')
            . L::card([
                '📱&nbsp; <strong>Graba en vertical,</strong> con buena luz y el móvil estable.',
                '📍&nbsp; <strong>Enseña lo que el cliente va a encontrar:</strong> el local, el producto, el ambiente.',
                '⏱️&nbsp; <strong>Mejor corto y directo,</strong> entre 15 y 30 segundos.',
            ])
            . L::paragraph('Si tienes cualquier duda, escríbenos al <strong>' . L::SUPPORT_PHONE
                . '</strong> y te ayudamos.')
            . L::signoff();

        $plainWhat = $videoTitle === null || trim($videoTitle) === ''
            ? 'el vídeo que subiste'
            : sprintf('tu vídeo «%s»', trim($videoTitle));

        $phone = L::SUPPORT_PHONE;
        $text  = <<<TEXT
        Hola,

        Hemos revisado {$plainWhat} y, en esta ocasión, no ha pasado la
        selección, así que aún no está publicado en el mapa.

        Únicamente aprobamos vídeos de valor, de buena calidad y con contexto.
        Revísalo, haz las modificaciones y vuelve a publicarlo teniendo en
        cuenta estos factores:

        - Graba en vertical, con buena luz y el móvil estable.
        - Enseña lo que el cliente va a encontrar: el local, el producto, el
          ambiente.
        - Mejor corto y directo, entre 15 y 30 segundos.

        Si tienes cualquier duda, escríbenos al {$phone} y te ayudamos.

        Un saludo,
        Equipo GOVEO
        TEXT;

        return new MailContent(
            'Tu vídeo en GOVEO necesita un ajuste',
            L::render(
                'Tu vídeo aún no está publicado. Te contamos cómo dejarlo listo para pasar la selección.',
                'Tu vídeo no ha sido aprobado en GOVEO',
                'Tu vídeo necesita un ajuste',
                $html,
            ),
            $text,
        );
    }

    /** Creador aprobado. Aquí sí hay nombre de persona, y se usa. */
    public static function publisherApproved(?string $publisherName, string $accountUrl): MailContent
    {
        $hi      = self::greeting($publisherName);
        $heading = $publisherName === null || trim($publisherName) === ''
            ? '¡Enhorabuena! 🎉'
            : sprintf('¡Enhorabuena, %s! 🎉', L::esc(trim($publisherName)));

        $html = L::paragraph(L::esc($hi))
            . L::paragraph('Tu cuenta de publisher ha sido aprobada y <strong>ya formas parte de la '
                . 'selección de creadores de GOVEO</strong>, la Vídeo Smart City de Madrid.')
            . L::paragraph('Tu cuenta ya está activa y puedes empezar ahora mismo:', '0 0 8px')
            . L::card([
                '🎬&nbsp; <strong>Publica tus GeoClips</strong> (vídeos cortos geolocalizados) de los lugares '
                    . 'y locales que quieras dar a conocer.',
                '👤&nbsp; <strong>Completa tu perfil de creador:</strong> foto, bio y enlaces a tus redes.',
                '📍&nbsp; <strong>Cada vídeo que publiques queda anclado en el mapa de su zona,</strong> donde '
                    . 'la gente explora y te sigue.',
                '📲&nbsp; <strong>Comparte tu perfil de GOVEO</strong> para conseguir seguidores y hacer crecer '
                    . 'tu comunidad.',
            ])
            . L::button('Entrar en mi cuenta', $accountUrl)
            . L::paragraph('Te recordamos que los vídeos pasan por nuestra selección antes de quedar '
                . 'visibles en el mapa. Te avisamos en cuanto estén publicados.')
            . L::highlight('💼&nbsp; Por último, recuerda que tenemos un <strong>Programa Partner de '
                . 'Creadores</strong> con el que puedes hacer tu cartera de negocios colaboradores y tener '
                . 'ingresos regulares.')
            . L::paragraph('Si tienes cualquier duda, escríbenos al <strong>' . L::SUPPORT_PHONE
                . '</strong> y te ayudamos.')
            . L::signoff('Bienvenido a GOVEO.');

        $phone = L::SUPPORT_PHONE;
        $text  = <<<TEXT
        {$hi}

        Tu cuenta de publisher ha sido aprobada y ya formas parte de la
        selección de creadores de GOVEO, la Vídeo Smart City de Madrid.

        Tu cuenta ya está activa y puedes empezar ahora mismo:

        - Publica tus GeoClips (vídeos cortos geolocalizados) de los lugares y
          locales que quieras dar a conocer.
        - Completa tu perfil de creador: foto, bio y enlaces a tus redes.
        - Cada vídeo que publiques queda anclado en el mapa de su zona.
        - Comparte tu perfil de GOVEO para conseguir seguidores.

        Entrar en mi cuenta:
        {$accountUrl}

        Los vídeos pasan por nuestra selección antes de quedar visibles en el
        mapa; te avisamos en cuanto estén publicados.

        Y recuerda que tenemos un Programa Partner de Creadores con el que
        puedes hacer tu cartera de negocios colaboradores y tener ingresos
        regulares.

        Si tienes cualquier duda, escríbenos al {$phone} y te ayudamos.

        Bienvenido a GOVEO. Un saludo,
        Equipo GOVEO
        TEXT;

        return new MailContent(
            '✅ Tu cuenta de publisher en GOVEO está aprobada',
            L::render(
                'Ya formas parte de la selección de creadores de GOVEO. Tu cuenta está activa.',
                'Tu cuenta de publisher en GOVEO está aprobada',
                $heading,
                $html,
            ),
            $text,
        );
    }

    /** Y el «no» de un creador, con lo que se busca dicho en concreto. */
    public static function publisherRejected(?string $publisherName): MailContent
    {
        $hi = self::greeting($publisherName);

        $html = L::paragraph(L::esc($hi))
            . L::paragraph('Antes de nada, gracias por solicitar tu cuenta de publisher en GOVEO y por '
                . 'querer formar parte de nuestra comunidad de creadores. De verdad que valoramos tu interés.')
            . L::paragraph('Hemos revisado tu solicitud y, en esta ocasión, no podemos aprobarla. GOVEO '
                . 'funciona como una selección cuidada de creadores, y ahora mismo tu perfil o tu tipo de '
                . 'contenido no encaja con lo que estamos incorporando a la red en esta etapa.')
            . L::paragraph('Esto no es una puerta cerrada:', '0 0 8px')
            . L::card([
                '🎬&nbsp; <strong>Trabaja tu contenido y vuelve a intentarlo.</strong> Buscamos vídeos de valor '
                    . 'sobre lugares y locales reales: en vertical, con buena luz, cortos y que enseñen lo '
                    . 'que uno va a encontrar en el sitio.',
                '🗂️&nbsp; <strong>Guardamos tus datos</strong> y, si más adelante ampliamos la selección de '
                    . 'creadores, te avisaremos los primeros.',
            ])
            . L::paragraph('Para cualquier duda, o si crees que ha habido un error en la revisión, '
                . 'respóndenos a este email o escríbenos por WhatsApp al <strong>' . L::SUPPORT_PHONE
                . '</strong>. Respondemos en el día.')
            . L::signoff('Gracias de nuevo por contar con nosotros.');

        $phone = L::SUPPORT_PHONE;
        $text  = <<<TEXT
        {$hi}

        Antes de nada, gracias por solicitar tu cuenta de publisher en GOVEO y
        por querer formar parte de nuestra comunidad de creadores.

        Hemos revisado tu solicitud y, en esta ocasión, no podemos aprobarla.
        GOVEO funciona como una selección cuidada de creadores, y ahora mismo tu
        perfil o tu tipo de contenido no encaja con lo que estamos incorporando
        a la red en esta etapa.

        Esto no es una puerta cerrada:

        - Trabaja tu contenido y vuelve a intentarlo. Buscamos vídeos de valor
          sobre lugares y locales reales: en vertical, con buena luz, cortos y
          que enseñen lo que uno va a encontrar en el sitio.
        - Guardamos tus datos y, si más adelante ampliamos la selección de
          creadores, te avisaremos los primeros.

        Para cualquier duda, o si crees que ha habido un error en la revisión,
        responde a este email o escríbenos por WhatsApp al {$phone}.

        Gracias de nuevo por contar con nosotros.

        Un saludo,
        Equipo GOVEO
        TEXT;

        return new MailContent(
            'Sobre tu solicitud de publisher en GOVEO',
            L::render(
                'Gracias por tu interés en ser creador de GOVEO. Te contamos el estado de tu solicitud.',
                'Sobre tu solicitud de publisher en GOVEO',
                'Gracias por tu interés en GOVEO',
                $html,
            ),
            $text,
        );
    }

    /** «Hola Ana,» si se sabe el nombre; «Hola,» si no. Nunca «Hola null,». */
    private static function greeting(?string $name): string
    {
        return $name !== null && trim($name) !== ''
            ? sprintf('Hola %s,', trim($name))
            : 'Hola,';
    }
}
