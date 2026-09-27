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
                        {[...social.providers].sort(byProvider).map((provider) => (
                            <a
                                key={provider.alias}
                                className="goveo-button goveo-button--social"
                                href={provider.loginUrl}
                            >
                                <ProviderGlyph providerId={provider.providerId} />
                                {msgStr('continueWith', providerName(provider))}
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

            {/* El registro de Keycloak está cerrado (`registrationAllowed=false`):
                las cuentas se crean en la web, con su casilla de condiciones, y
                ahí se manda. Si algún día se abre, se usa el suyo. */}
            {realm.password && (
                <p className="goveo-foot">
                    {msgStr('noAccount')}{' '}
                    <a
                        className="goveo-link"
                        href={
                            realm.registrationAllowed && !registrationDisabled && url.registrationUrl
                                ? url.registrationUrl
                                : `${kcContext.properties.GOVEO_WEB_URL.replace(/\/+$/, '')}/registro`
                        }
                    >
                        {msgStr('doRegister')}
                    </a>
                </p>
            )}
        </Template>
    )
}

/**
 * El nombre del proveedor en el botón. No el `displayName` del realm, que es
 * «Sign in with Google» y dejaba «Continúa con Sign in with Google».
 */
const PROVIDER_NAMES: Record<string, string> = { google: 'Google', apple: 'Apple' }

function providerName(provider: { providerId: string; displayName: string }): string {
    return PROVIDER_NAMES[provider.providerId] ?? provider.displayName
}

/** Google primero y luego Apple, como en la app; cualquier otro, detrás. */
function byProvider(a: { providerId: string }, b: { providerId: string }): number {
    const order = ['google', 'apple']
    const rank = (id: string) => (order.includes(id) ? order.indexOf(id) : order.length)
    return rank(a.providerId) - rank(b.providerId)
}

/**
 * El icono de cada proveedor, los mismos que la app: la «G» a color de Google
 * y la manzana en negro. Van dibujados aquí y no como imagen para no depender de
 * que Keycloak sirva un fichero más; un proveedor que no sea ninguno de los dos
 * sale sin icono.
 */
function ProviderGlyph({ providerId }: { providerId: string }) {
    if (providerId === 'google') {
        return (
            <svg width="20" height="20" viewBox="0 0 48 48" aria-hidden="true">
                <path
                    fill="#EA4335"
                    d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"
                />
                <path
                    fill="#4285F4"
                    d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"
                />
                <path
                    fill="#FBBC05"
                    d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"
                />
                <path
                    fill="#34A853"
                    d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"
                />
            </svg>
        )
    }
    if (providerId === 'apple') {
        return (
            <svg width="20" height="20" viewBox="0 0 384 512" aria-hidden="true">
                <path
                    fill="#000000"
                    d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5c0 26.2 4.8 53.3 14.4 81.2 12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"
                />
            </svg>
        )
    }
    return null
}

/** En el flujo en dos pasos el correo ya está puesto y no se vuelve a pedir. */
function usernameHidden(kcContext: Extract<KcContext, { pageId: 'login.ftl' }>): boolean {
    return kcContext.usernameHidden === true
}
