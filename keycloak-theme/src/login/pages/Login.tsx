import { useState } from 'react'
import type { PageProps } from 'keycloakify/login/pages/PageProps'
import type { KcContext } from '../KcContext'
import type { I18n } from '../i18n'
import { kcSanitize } from 'keycloakify/lib/kcSanitize'

/**
 * La pantalla de entrar.
 *
 * Lleva lo mismo que la de la app y en el mismo orden: los accesos con Google y
 * Apple arriba —son los que usa la mayoría— y debajo el correo y la contraseña.
 * El enlace de «¿Has olvidado tu contraseña?» va junto al campo, no al final:
 * quien lo busca es porque ya ha fallado al escribirla.
 *
 * No hay registro: el realm lo tiene apagado (`registrationAllowed=false`) y las
 * altas se hacen por la app o por el formulario de negocio.
 */
export default function Login(
    props: PageProps<Extract<KcContext, { pageId: 'login.ftl' }>, I18n>
) {
    const { kcContext, i18n, doUseDefaultCss, Template, classes } = props
    const { social, realm, url, login, auth, registrationDisabled, messagesPerField } = kcContext
    const { msg, msgStr } = i18n

    const [revealed, setRevealed] = useState(false)
    // Keycloak rechaza el segundo envío del mismo formulario, así que el botón
    // se desactiva al enviar: sin eso, un doble clic acaba en «página caducada».
    const [sending, setSending] = useState(false)

    return (
        <Template
            kcContext={kcContext}
            i18n={i18n}
            doUseDefaultCss={doUseDefaultCss}
            classes={classes}
            displayMessage={!messagesPerField.existsError('username', 'password')}
            headerNode={msg('loginAccountTitle')}
        >
            <p className="goveo-subtitle">{msgStr('loginSubtitle')}</p>

            {realm.password && social?.providers !== undefined && social.providers.length > 0 && (
                <>
                    <div className="goveo-social">
                        {social.providers.map((provider) => (
                            <a
                                key={provider.alias}
                                className="goveo-button goveo-button--secondary"
                                href={provider.loginUrl}
                            >
                                {provider.displayName}
                            </a>
                        ))}
                    </div>
                    <div className="goveo-separator">{msgStr('identityProviderSeparator')}</div>
                </>
            )}

            {realm.password && (
                <form
                    action={url.loginAction}
                    method="post"
                    onSubmit={() => {
                        setSending(true)
                        return true
                    }}
                >
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
                            defaultValue={login.username ?? ''}
                            aria-invalid={messagesPerField.existsError('username', 'password')}
                        />
                        {messagesPerField.existsError('username', 'password') && (
                            <span
                                className="goveo-field-error"
                                dangerouslySetInnerHTML={{
                                    __html: kcSanitize(
                                        messagesPerField.getFirstError('username', 'password')
                                    ),
                                }}
                            />
                        )}
                    </div>

                    <div className="goveo-field">
                        <label className="goveo-label" htmlFor="password">
                            {msgStr('password')}
                        </label>
                        <div className="goveo-input-wrap">
                            <input
                                id="password"
                                name="password"
                                className="goveo-input"
                                type={revealed ? 'text' : 'password'}
                                autoComplete="current-password"
                                placeholder={msgStr('typePassword')}
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
                    </div>

                    <div className="goveo-row">
                        {realm.rememberMe && !usernameHidden(kcContext) ? (
                            <label className="goveo-checkbox">
                                <input
                                    type="checkbox"
                                    name="rememberMe"
                                    defaultChecked={!!login.rememberMe}
                                />
                                {msgStr('rememberMe')}
                            </label>
                        ) : (
                            <span />
                        )}

                        {realm.resetPasswordAllowed && (
                            <a className="goveo-link" href={url.loginResetCredentialsUrl}>
                                {msgStr('doForgotPassword')}
                            </a>
                        )}
                    </div>

                    {/* Lo que le dice a Keycloak que este envío es el de este formulario. */}
                    <input
                        type="hidden"
                        name="credentialId"
                        value={auth.selectedCredential ?? ''}
                    />

                    <button className="goveo-button" type="submit" name="login" disabled={sending}>
                        {msgStr('doLogIn')}
                    </button>
                </form>
            )}

            {realm.password &&
                realm.registrationAllowed &&
                !registrationDisabled &&
                url.registrationUrl !== undefined && (
                    <p className="goveo-foot">
                        <a className="goveo-link" href={url.registrationUrl}>
                            {msgStr('doRegister')}
                        </a>
                    </p>
                )}
        </Template>
    )
}

/** En el flujo en dos pasos el correo ya está puesto y no se vuelve a pedir. */
function usernameHidden(kcContext: Extract<KcContext, { pageId: 'login.ftl' }>): boolean {
    return kcContext.usernameHidden === true
}
