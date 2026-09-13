import { useEffect } from 'react'
import type { TemplateProps } from 'keycloakify/login/TemplateProps'
import { useInitialize } from 'keycloakify/login/Template.useInitialize'
import { useSetClassName } from 'keycloakify/tools/useSetClassName'
// Los mensajes de Keycloak llevan HTML (enlaces, negritas) y hay que pintarlos
// como tal; `kcSanitize` es lo que impide que ese HTML traiga algo más.
import { kcSanitize } from 'keycloakify/lib/kcSanitize'
import type { I18n } from '../i18n'
import type { KcContext } from '../KcContext'

/**
 * La caja de todas las pantallas: fondo oscuro, la marca arriba y una tarjeta.
 *
 * Es la misma cara que el login de la app (fondo oscuro, acento naranja): quien
 * llega aquí viene de la app o de goveo.app, y una pantalla de contraseña que no
 * se parece a nada es justo lo que enseña una web de phishing.
 *
 * El `headerNode` de cada página se pinta como título dentro de la tarjeta, no
 * como cabecera de Keycloak: lo que manda en la pantalla es la acción, y una
 * cabecera propia le robaba el sitio.
 */
export default function Template(props: TemplateProps<KcContext, I18n>) {
    const {
        displayMessage = true,
        headerNode,
        displayInfo = false,
        infoNode = null,
        documentTitle,
        bodyClassName,
        kcContext,
        i18n,
        doUseDefaultCss,
        children,
    } = props

    const { msgStr } = i18n
    const { message, isAppInitiatedAction } = kcContext

    useEffect(() => {
        document.title = documentTitle ?? msgStr('loginTitle', kcContext.realm.displayName)
    }, [])

    useSetClassName({ qualifiedName: 'html', className: undefined })
    useSetClassName({ qualifiedName: 'body', className: bodyClassName })

    // Carga las hojas de estilo de Keycloak si tocara. Hasta que resuelve, la
    // página no se pinta: enseñarla a medio estilar es peor que esperar.
    const { isReadyToRender } = useInitialize({ kcContext, doUseDefaultCss })

    if (!isReadyToRender) {
        return null
    }

    return (
        <div className="goveo-page">
            <main className="goveo-card">
                <p className="goveo-wordmark">GOVEO</p>
                <div className="goveo-rule" />
                <p className="goveo-tagline">La Vídeo Smart City de Madrid</p>

                {headerNode !== null && <h1 className="goveo-title">{headerNode}</h1>}

                {displayMessage &&
                    message !== undefined &&
                    (message.type !== 'warning' || !isAppInitiatedAction) && (
                        <div
                            className={`goveo-alert goveo-alert--${
                                message.type === 'error' ? 'error' : 'success'
                            }`}
                        >
                            <span
                                dangerouslySetInnerHTML={{ __html: kcSanitize(message.summary) }}
                            />
                        </div>
                    )}

                {children}

                {displayInfo && <div className="goveo-foot">{infoNode}</div>}
            </main>
        </div>
    )
}
