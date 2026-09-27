import type { PageProps } from 'keycloakify/login/pages/PageProps'
import DefaultLogoutConfirm from 'keycloakify/login/pages/LogoutConfirm'
import type { KcContext } from '../KcContext'
import type { I18n } from '../i18n'
import { isSwitching } from '../switchAccount'

/**
 * «¿Quieres cerrar sesión?» de Keycloak.
 *
 * Si el cierre lo ha pedido el cambio de cuenta (`switchAccount`), se confirma
 * solo: quien lo ha pedido ya ha dicho que quiere otra cuenta. Si no, se
 * pregunta como siempre.
 */
export default function LogoutConfirm(
    props: PageProps<Extract<KcContext, { pageId: 'logout-confirm.ftl' }>, I18n>
) {
    const { kcContext, i18n, doUseDefaultCss, Template, classes } = props
    const auto = isSwitching()

    if (!auto) return <DefaultLogoutConfirm {...props} />

    return (
        <Template
            kcContext={kcContext}
            i18n={i18n}
            doUseDefaultCss={doUseDefaultCss}
            classes={classes}
            displayMessage={false}
            headerNode={i18n.msgStr('switchingAccount')}
        >
            {/* Se envía al montarse, no en un efecto: `Template` no pinta nada
                hasta cargar sus estilos, y un efecto de aquí llegaba antes de
                que el formulario existiera. */}
            <form ref={submitOnce} action={kcContext.url.logoutConfirmAction} method="POST">
                <input type="hidden" name="session_code" value={kcContext.logoutConfirm.code} />
                <input type="hidden" name="confirmLogout" value="true" />
            </form>
        </Template>
    )
}

let submitted = false

function submitOnce(form: HTMLFormElement | null) {
    if (!form || submitted) return
    submitted = true
    form.requestSubmit()
}
