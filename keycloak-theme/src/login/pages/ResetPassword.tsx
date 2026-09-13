import type { PageProps } from 'keycloakify/login/pages/PageProps'
import { kcSanitize } from 'keycloakify/lib/kcSanitize'
import type { KcContext } from '../KcContext'
import type { I18n } from '../i18n'

/**
 * «He olvidado mi contraseña»: se pide el correo y Keycloak manda el enlace.
 *
 * **Contesta lo mismo exista o no la cuenta** —es Keycloak quien lo hace, y está
 * bien—: responder distinto convertiría la pantalla en un comprobador de qué
 * direcciones tienen cuenta en Goveo.
 */
export default function ResetPassword(
    props: PageProps<Extract<KcContext, { pageId: 'login-reset-password.ftl' }>, I18n>
) {
    const { kcContext, i18n, doUseDefaultCss, Template, classes } = props
    const { url, messagesPerField } = kcContext
    const { msg, msgStr } = i18n

    return (
        <Template
            kcContext={kcContext}
            i18n={i18n}
            doUseDefaultCss={doUseDefaultCss}
            classes={classes}
            displayMessage={!messagesPerField.existsError('username')}
            headerNode={msg('emailForgotTitle')}
            displayInfo
            infoNode={
                <a className="goveo-link" href={url.loginUrl}>
                    {msgStr('doBack')}
                </a>
            }
        >
            <p className="goveo-subtitle">{msgStr('emailInstruction')}</p>

            <form action={url.loginAction} method="post">
                <div className="goveo-field">
                    <label className="goveo-label" htmlFor="username">
                        {msgStr('email')}
                    </label>
                    <input
                        id="username"
                        name="username"
                        className="goveo-input"
                        type="email"
                        autoFocus
                        autoComplete="username"
                        placeholder={msgStr('typeEmail')}
                        aria-invalid={messagesPerField.existsError('username')}
                    />
                    {messagesPerField.existsError('username') && (
                        <span
                            className="goveo-field-error"
                            dangerouslySetInnerHTML={{
                                __html: kcSanitize(messagesPerField.get('username')),
                            }}
                        />
                    )}
                </div>

                <button className="goveo-button" type="submit">
                    {msgStr('doSubmit')}
                </button>
            </form>
        </Template>
    )
}
