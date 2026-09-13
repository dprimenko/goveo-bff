import type { PageProps } from 'keycloakify/login/pages/PageProps'
import { kcSanitize } from 'keycloakify/lib/kcSanitize'
import type { KcContext } from '../KcContext'
import type { I18n } from '../i18n'

/**
 * El paso intermedio: «pulsa aquí para continuar».
 *
 * Sale entre el correo y el formulario de contraseña, y **no sobra**: el token
 * del enlace es de un solo uso, y sin ese clic bastaría con que el
 * previsualizador de enlaces del cliente de correo —o el antivirus del
 * servidor— lo abriera para quemarlo. El usuario recibiría un enlace ya usado
 * sin haberlo tocado nadie.
 *
 * Lo que sí sobraba era cómo se veía: la página de serie pinta un enlace azul
 * subrayado en medio de la nada. Aquí es un botón naranja, como el del correo
 * del que viene, así que se entiende que es la continuación de lo mismo.
 */
export default function Info(props: PageProps<Extract<KcContext, { pageId: 'info.ftl' }>, I18n>) {
    const { kcContext, i18n, doUseDefaultCss, Template, classes } = props
    const { messageHeader, message, requiredActions, skipLink, pageRedirectUri, actionUri, client } =
        kcContext
    const { msgStr, advancedMsg } = i18n

    /**
     * El caso que de verdad se ve: el enlace del correo de recuperación. Ahí
     * Keycloak dice «Realice las siguientes acciones» y lista «Cambiar la
     * contraseña», que es cierto y no dice nada. Se cuenta con nuestras
     * palabras; el texto de Keycloak se queda para los demás casos, que son
     * raros y no merecen una redacción propia.
     */
    const isPasswordStep =
        actionUri !== undefined &&
        requiredActions?.length === 1 &&
        requiredActions[0] === 'UPDATE_PASSWORD'

    // A dónde sigue: la acción pendiente si la hay, y si no, de vuelta a la
    // aplicación. Sin ninguna de las dos no hay botón que pintar — es una
    // pantalla informativa y ya está.
    const href = !skipLink ? (actionUri || pageRedirectUri || client?.baseUrl) : undefined

    return (
        <Template
            kcContext={kcContext}
            i18n={i18n}
            doUseDefaultCss={doUseDefaultCss}
            classes={classes}
            displayMessage={false}
            headerNode={
                isPasswordStep ? (
                    msgStr('infoPasswordTitle')
                ) : (
                    (messageHeader ?? <span>{message.summary}</span>)
                )
            }
        >
            {isPasswordStep && <p className="goveo-subtitle">{msgStr('infoPasswordBody')}</p>}

            {!isPasswordStep && messageHeader !== undefined && (
                <p
                    className="goveo-subtitle"
                    dangerouslySetInnerHTML={{ __html: kcSanitize(message.summary) }}
                />
            )}

            {!isPasswordStep && requiredActions !== undefined && requiredActions.length > 0 && (
                <p className="goveo-subtitle">
                    {requiredActions
                        .map((action) => advancedMsg(`requiredAction.${action}`))
                        .reduce<React.ReactNode[]>(
                            (all, node, i) => (i === 0 ? [node] : [...all, ', ', node]),
                            [],
                        )}
                </p>
            )}

            {href !== undefined && (
                <a className="goveo-button" href={href}>
                    {isPasswordStep
                        ? msgStr('infoPasswordCta')
                        : msgStr(actionUri ? 'doContinue' : 'backToApplication')}
                </a>
            )}
        </Template>
    )
}
