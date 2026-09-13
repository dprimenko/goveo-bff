import '../assets/styles/index.css'
import { Suspense, lazy } from 'react'
import type { ClassKey } from 'keycloakify/login'
import type { KcContext } from './KcContext'
import { useI18n } from './i18n'
import Template from './template/Template.tsx'
import BasePage from './pages/Base.tsx'

const UserProfileFormFields = lazy(() => import('keycloakify/login/UserProfileFormFields'))

/** Pedir la contraseña dos veces al crearla: escribirla mal no tiene vuelta. */
const doMakeUserConfirmPassword = true

export default function KcPage(props: { kcContext: KcContext }) {
    const { kcContext } = props
    const { i18n } = useI18n({ kcContext })

    return (
        <Suspense>
            <BasePage
                kcContext={kcContext}
                i18n={i18n}
                classes={classes}
                Template={Template}
                doUseDefaultCss={false}
                UserProfileFormFields={UserProfileFormFields}
                doMakeUserConfirmPassword={doMakeUserConfirmPassword}
            />
        </Suspense>
    )
}

const classes = {} satisfies { [key in ClassKey]?: string }
