import { i18nBuilder } from 'keycloakify/login'
import type { ThemeName } from '../kc.gen'

/**
 * Los textos de las pantallas.
 *
 * Se sobreescriben los de Keycloak en vez de escribirlos dentro de cada página
 * porque son los mismos que ya usa la app —«Acceder», «¿Has olvidado tu
 * contraseña?»— y porque así el inglés sale solo: lo que no se traduzca aquí cae
 * en el texto de serie de Keycloak, que existe en los dos idiomas.
 *
 * @see https://docs.keycloakify.dev/features/i18n
 */
const { useI18n, ofTypeI18n } = i18nBuilder
    .withThemeName<ThemeName>()
    .withCustomTranslations({
        es: {
            loginAccountTitle: 'Entra en Goveo',
            loginSubtitle: 'Entra con tu cuenta para gestionar tu negocio y tus vídeos.',
            email: 'Correo electrónico',
            password: 'Contraseña',
            typeEmail: 'tucorreo@ejemplo.com',
            typePassword: 'Tu contraseña',
            rememberMe: 'No cerrar sesión',
            doForgotPassword: '¿Has olvidado tu contraseña?',
            doLogIn: 'Acceder',
            doSubmit: 'Enviar',
            doBack: 'Volver',
            doContinue: 'Continuar',
            showPassword: 'Ver la contraseña',
            hidePassword: 'Ocultar la contraseña',
            identityProviderSeparator: 'o',
            // Recuperar la contraseña
            emailForgotTitle: '¿Has olvidado tu contraseña?',
            emailInstruction:
                'Escribe tu correo y te mandamos un enlace para elegir una nueva.',
            // La pantalla a la que lleva ese enlace
            updatePasswordTitle: 'Elige una contraseña nueva',
            updatePasswordSubtitle:
                'Tiene que tener al menos 8 caracteres. Al guardarla entrarás con ella.',
            passwordNew: 'Contraseña nueva',
            passwordConfirm: 'Repite la contraseña',
            doSave: 'Guardar y entrar',
            // El paso intermedio del enlace del correo
            infoPasswordTitle: 'Ya casi está',
            infoPasswordBody:
                'Pulsa el botón para elegir tu contraseña nueva. El enlace sólo se puede usar una vez.',
            infoPasswordCta: 'Elegir mi contraseña',
            // Confirmación de correo
            emailVerifyTitle: 'Confirma tu correo',
            emailVerifyInstruction1:
                'Te hemos mandado un correo con un enlace para confirmar tu dirección.',
            emailVerifyInstruction2: '¿No te ha llegado?',
            emailVerifyInstruction3: 'Pedir otro correo',
        },
        en: {
            loginAccountTitle: 'Sign in to Goveo',
            loginSubtitle: 'Sign in to manage your business and your videos.',
            email: 'Email',
            password: 'Password',
            typeEmail: 'you@example.com',
            typePassword: 'Your password',
            rememberMe: 'Keep me signed in',
            doForgotPassword: 'Forgot your password?',
            doLogIn: 'Sign in',
            doSubmit: 'Send',
            doBack: 'Back',
            doContinue: 'Continue',
            showPassword: 'Show password',
            hidePassword: 'Hide password',
            identityProviderSeparator: 'or',
            emailForgotTitle: 'Forgot your password?',
            emailInstruction:
                "Enter your email and we'll send you a link to choose a new one.",
            updatePasswordTitle: 'Choose a new password',
            updatePasswordSubtitle:
                'At least 8 characters. You will be signed in once you save it.',
            passwordNew: 'New password',
            passwordConfirm: 'Repeat the password',
            doSave: 'Save and sign in',
            infoPasswordTitle: 'Almost there',
            infoPasswordBody:
                'Tap the button to choose your new password. The link can only be used once.',
            infoPasswordCta: 'Choose my password',
            emailVerifyTitle: 'Confirm your email',
            emailVerifyInstruction1:
                "We've sent you an email with a link to confirm your address.",
            emailVerifyInstruction2: "Didn't get it?",
            emailVerifyInstruction3: 'Send another email',
        },
    })
    .build()

type I18n = typeof ofTypeI18n

export { useI18n, type I18n }
