import type { GetMessages } from 'keycloakify-emails'

/**
 * Los textos de los correos que manda Keycloak.
 *
 * Están aquí y no dentro de cada plantilla para que traducir no sea recorrer
 * cuatro ficheros por correo, y para que se pueda leer de un tirón lo que le
 * llega al usuario — que es lo que de verdad se revisa.
 *
 * El tono es el mismo que el del correo que manda el BFF (bienvenida, altas
 * aprobadas): se dice qué ha pasado en la primera línea, hay un solo botón, y lo
 * que no es una acción va en letra pequeña.
 */
export type Lang = 'en' | 'es'

const translations = {
    es: {
        footer: {
            receivingBecause:
                'Recibes este correo porque tienes una cuenta en GOVEO.',
            copyright: (year: number) =>
                `© ${year} Goveo · Globaly Digital Emotion, S.L. · Madrid`,
        },
        passwordReset: {
            subject: 'Restablece tu contraseña de GOVEO',
            preview: 'Has pedido cambiar la contraseña de tu cuenta de GOVEO.',
            heading: '¿Has olvidado tu contraseña?',
            greeting: 'Hola,',
            body: 'Has pedido cambiar la contraseña de tu cuenta de GOVEO. Pulsa el botón y elige una nueva.',
            cta: 'Crear una contraseña nueva',
            expiry: 'El enlace caduca en',
            ignore: 'Si no has sido tú, ignora este correo: tu contraseña no cambia hasta que se use el enlace.',
        },
        executeActions: {
            subject: 'Restablece tu contraseña de GOVEO',
            preview: 'Has pedido cambiar la contraseña de tu cuenta de GOVEO.',
            heading: '¿Has olvidado tu contraseña?',
            greeting: 'Hola,',
            body: 'Has pedido cambiar la contraseña de tu cuenta de GOVEO. Pulsa el botón y elige una nueva.',
            cta: 'Crear una contraseña nueva',
            expiry: 'El enlace caduca en',
            ignore: 'Si no has sido tú, ignora este correo: tu contraseña no cambia hasta que se use el enlace.',
        },
        emailVerification: {
            subject: 'Confirma tu correo en GOVEO',
            preview: 'Confirma tu dirección para terminar de activar tu cuenta.',
            heading: 'Confirma tu correo',
            greeting: 'Hola,',
            body: 'Alguien ha creado una cuenta en GOVEO con esta dirección. Si has sido tú, confírmala para terminar.',
            cta: 'Confirmar mi correo',
            expiry: 'El enlace caduca en',
            ignore: 'Si no has creado ninguna cuenta, ignora este correo.',
        },
        emailTest: {
            subject: 'GOVEO — prueba de envío',
            preview: 'Prueba de la configuración de correo de GOVEO.',
            heading: 'Prueba de envío',
            body: 'Si estás leyendo esto, la configuración de correo del realm funciona.',
        },
    },
    en: {
        footer: {
            receivingBecause:
                'You are receiving this email because you have a GOVEO account.',
            copyright: (year: number) =>
                `© ${year} Goveo · Globaly Digital Emotion, S.L. · Madrid`,
        },
        passwordReset: {
            subject: 'Reset your GOVEO password',
            preview: 'You asked to change the password of your GOVEO account.',
            heading: 'Forgot your password?',
            greeting: 'Hi,',
            body: 'You asked to change the password of your GOVEO account. Tap the button and choose a new one.',
            cta: 'Set a new password',
            expiry: 'This link expires in',
            ignore: 'If this was not you, ignore this email: your password does not change until the link is used.',
        },
        executeActions: {
            subject: 'Reset your GOVEO password',
            preview: 'You asked to change the password of your GOVEO account.',
            heading: 'Forgot your password?',
            greeting: 'Hi,',
            body: 'You asked to change the password of your GOVEO account. Tap the button and choose a new one.',
            cta: 'Set a new password',
            expiry: 'This link expires in',
            ignore: 'If this was not you, ignore this email: your password does not change until the link is used.',
        },
        emailVerification: {
            subject: 'Confirm your email on GOVEO',
            preview: 'Confirm your address to finish activating your account.',
            heading: 'Confirm your email',
            greeting: 'Hi,',
            body: 'Someone created a GOVEO account with this address. If it was you, confirm it to finish.',
            cta: 'Confirm my email',
            expiry: 'This link expires in',
            ignore: 'If you did not create an account, ignore this email.',
        },
        emailTest: {
            subject: 'GOVEO — delivery test',
            preview: 'Test of the GOVEO email configuration.',
            heading: 'Delivery test',
            body: 'If you are reading this, the realm email configuration works.',
        },
    },
} as const

export type Translations = (typeof translations)[Lang]

export function getTranslations(locale: string): Translations {
    return locale === 'es' ? translations.es : translations.en
}

/**
 * Los nombres de las acciones pendientes, que Keycloak inserta por su cuenta en
 * algunos correos. Sin traducir salen en inglés en medio de un texto en español.
 */
export const getMessages: GetMessages = ({ locale }) => {
    if (locale === 'es') {
        return {
            'requiredAction.CONFIGURE_TOTP': 'Configurar la verificación en dos pasos',
            'requiredAction.UPDATE_PASSWORD': 'Cambiar la contraseña',
            'requiredAction.UPDATE_PROFILE': 'Completar el perfil',
            'requiredAction.VERIFY_EMAIL': 'Confirmar el correo',
            'requiredAction.TERMS_AND_CONDITIONS': 'Aceptar los términos',
        }
    }

    return {
        'requiredAction.CONFIGURE_TOTP': 'Configure two-factor authentication',
        'requiredAction.UPDATE_PASSWORD': 'Update password',
        'requiredAction.UPDATE_PROFILE': 'Complete profile',
        'requiredAction.VERIFY_EMAIL': 'Verify email',
        'requiredAction.TERMS_AND_CONDITIONS': 'Accept the terms',
    }
}
