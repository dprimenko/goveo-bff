import { useState } from 'react'
import type { PageProps } from 'keycloakify/login/pages/PageProps'
import { kcSanitize } from 'keycloakify/lib/kcSanitize'
import type { KcContext } from '../KcContext'
import type { I18n } from '../i18n'

/**
 * Donde aterriza el enlace del correo: elegir la contraseña nueva.
 *
 * Es **la pantalla que más se va a ver de todo el tema** —es a la que lleva
 * «¿Has olvidado tu contraseña?»—, así que va escrita y no heredada de
 * Keycloakify: la de serie enseña «Update password» y dos campos sin explicar
 * que al guardar ya se entra.
 */
export default function LoginUpdatePassword(
    props: PageProps<Extract<KcContext, { pageId: 'login-update-password.ftl' }>, I18n>
) {
    const { kcContext, i18n, doUseDefaultCss, Template, classes } = props
    const { url, messagesPerField, isAppInitiatedAction } = kcContext
    const { msg, msgStr } = i18n

    const [revealed, setRevealed] = useState(false)

    return (
        <Template
            kcContext={kcContext}
            i18n={i18n}
            doUseDefaultCss={doUseDefaultCss}
            classes={classes}
            displayMessage={!messagesPerField.existsError('password', 'password-confirm')}
            headerNode={msg('updatePasswordTitle')}
        >
            <p className="goveo-subtitle">{msgStr('updatePasswordSubtitle')}</p>

            <form action={url.loginAction} method="post">
                <div className="goveo-field">
                    <label className="goveo-label" htmlFor="password-new">
                        {msgStr('passwordNew')}
                    </label>
                    <div className="goveo-input-wrap">
                        <input
                            id="password-new"
                            name="password-new"
                            className="goveo-input"
                            type={revealed ? 'text' : 'password'}
                            autoFocus
                            autoComplete="new-password"
                            aria-invalid={messagesPerField.existsError('password', 'password-confirm')}
                        />
                        <button
                            type="button"
                            className="goveo-reveal"
                            aria-label={msgStr(revealed ? 'hidePassword' : 'showPassword')}
                            onClick={() => setRevealed((v) => !v)}
                        >
                            {revealed ? '🙈' : '👁'}
                        </button>
                    </div>
                    {messagesPerField.existsError('password') && (
                        <span
                            className="goveo-field-error"
                            dangerouslySetInnerHTML={{
                                __html: kcSanitize(messagesPerField.get('password')),
                            }}
                        />
                    )}
                </div>

                <div className="goveo-field">
                    <label className="goveo-label" htmlFor="password-confirm">
                        {msgStr('passwordConfirm')}
                    </label>
                    <input
                        id="password-confirm"
                        name="password-confirm"
                        className="goveo-input"
                        type={revealed ? 'text' : 'password'}
                        autoComplete="new-password"
                        aria-invalid={messagesPerField.existsError('password-confirm')}
                    />
                    {messagesPerField.existsError('password-confirm') && (
                        <span
                            className="goveo-field-error"
                            dangerouslySetInnerHTML={{
                                __html: kcSanitize(messagesPerField.get('password-confirm')),
                            }}
                        />
                    )}
                </div>

                <button className="goveo-button" type="submit">
                    {msgStr('doSave')}
                </button>

                {/* Sólo cuando la acción la pidió la propia aplicación se puede
                    posponer; si se llegó por el correo, no hay a dónde volver. */}
                {isAppInitiatedAction && (
                    <button
                        className="goveo-button goveo-button--secondary"
                        type="submit"
                        name="cancel-aia"
                        value="true"
                        style={{ marginTop: 12 }}
                    >
                        {msgStr('doCancel')}
                    </button>
                )}
            </form>
        </Template>
    )
}
