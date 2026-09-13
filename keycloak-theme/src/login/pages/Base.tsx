import { lazy, Suspense, type JSX } from 'react'
import type { LazyOrNot } from 'keycloakify/tools/LazyOrNot'
import type { PageProps } from 'keycloakify/login/pages/PageProps'
import type { UserProfileFormFieldsProps } from 'keycloakify/login/UserProfileFormFieldsProps'
import type { I18n } from '../i18n'
import type { KcContext } from '../KcContext'

/**
 * Qué página pinta cada `pageId`.
 *
 * Escritas a mano sólo las tres que se usan de verdad —entrar, pedir el enlace y
 * elegir la contraseña nueva—; el resto sale de Keycloakify, que ya las trae, y
 * hereda igualmente nuestra caja porque todas pasan por el mismo `Template`.
 * Escribir treinta pantallas que nadie va a ver para que se parezcan a la marca
 * es trabajo que envejece solo.
 */
const Login = lazy(() => import('./Login.tsx'))
const ResetPassword = lazy(() => import('./ResetPassword.tsx'))
const LoginUpdatePassword = lazy(() => import('./LoginUpdatePassword.tsx'))
const Info = lazy(() => import('./Info.tsx'))

const Error = lazy(() => import('keycloakify/login/pages/Error'))
const LoginVerifyEmail = lazy(() => import('keycloakify/login/pages/LoginVerifyEmail'))
const LoginPageExpired = lazy(() => import('keycloakify/login/pages/LoginPageExpired'))
const LoginIdpLinkConfirm = lazy(() => import('keycloakify/login/pages/LoginIdpLinkConfirm'))
const LoginIdpLinkEmail = lazy(() => import('keycloakify/login/pages/LoginIdpLinkEmail'))
const LoginOtp = lazy(() => import('keycloakify/login/pages/LoginOtp'))
const LoginPassword = lazy(() => import('keycloakify/login/pages/LoginPassword'))
const LoginUsername = lazy(() => import('keycloakify/login/pages/LoginUsername'))
const LoginUpdateProfile = lazy(() => import('keycloakify/login/pages/LoginUpdateProfile'))
const LoginConfigTotp = lazy(() => import('keycloakify/login/pages/LoginConfigTotp'))
const LogoutConfirm = lazy(() => import('keycloakify/login/pages/LogoutConfirm'))
const IdpReviewUserProfile = lazy(() => import('keycloakify/login/pages/IdpReviewUserProfile'))
const UpdateEmail = lazy(() => import('keycloakify/login/pages/UpdateEmail'))
const SelectAuthenticator = lazy(() => import('keycloakify/login/pages/SelectAuthenticator'))
const Terms = lazy(() => import('keycloakify/login/pages/Terms'))
const Register = lazy(() => import('keycloakify/login/pages/Register'))
const DeleteAccountConfirm = lazy(() => import('keycloakify/login/pages/DeleteAccountConfirm'))
const FrontchannelLogout = lazy(() => import('keycloakify/login/pages/FrontchannelLogout'))
const DeleteCredential = lazy(() => import('keycloakify/login/pages/DeleteCredential'))
const Code = lazy(() => import('keycloakify/login/pages/Code'))

type BasePageProps = PageProps<KcContext, I18n> & {
    UserProfileFormFields: LazyOrNot<(props: UserProfileFormFieldsProps) => JSX.Element>
    doMakeUserConfirmPassword: boolean
}

export default function BasePage(props: BasePageProps) {
    const { kcContext, ...rest } = props

    return (
        <Suspense>
            {(() => {
                switch (kcContext.pageId) {
                    case 'login.ftl':
                        return <Login kcContext={kcContext} {...rest} />
                    case 'login-reset-password.ftl':
                        return <ResetPassword kcContext={kcContext} {...rest} />
                    case 'login-update-password.ftl':
                        return <LoginUpdatePassword kcContext={kcContext} {...rest} />
                    case 'info.ftl':
                        return <Info kcContext={kcContext} {...rest} />
                    case 'error.ftl':
                        return <Error kcContext={kcContext} {...rest} />
                    case 'login-verify-email.ftl':
                        return <LoginVerifyEmail kcContext={kcContext} {...rest} />
                    case 'login-page-expired.ftl':
                        return <LoginPageExpired kcContext={kcContext} {...rest} />
                    case 'login-idp-link-confirm.ftl':
                        return <LoginIdpLinkConfirm kcContext={kcContext} {...rest} />
                    case 'login-idp-link-email.ftl':
                        return <LoginIdpLinkEmail kcContext={kcContext} {...rest} />
                    case 'login-otp.ftl':
                        return <LoginOtp kcContext={kcContext} {...rest} />
                    case 'login-password.ftl':
                        return <LoginPassword kcContext={kcContext} {...rest} />
                    case 'login-username.ftl':
                        return <LoginUsername kcContext={kcContext} {...rest} />
                    case 'login-update-profile.ftl':
                        return <LoginUpdateProfile kcContext={kcContext} {...rest} />
                    case 'login-config-totp.ftl':
                        return <LoginConfigTotp kcContext={kcContext} {...rest} />
                    case 'logout-confirm.ftl':
                        return <LogoutConfirm kcContext={kcContext} {...rest} />
                    case 'idp-review-user-profile.ftl':
                        return <IdpReviewUserProfile kcContext={kcContext} {...rest} />
                    case 'update-email.ftl':
                        return <UpdateEmail kcContext={kcContext} {...rest} />
                    case 'select-authenticator.ftl':
                        return <SelectAuthenticator kcContext={kcContext} {...rest} />
                    case 'terms.ftl':
                        return <Terms kcContext={kcContext} {...rest} />
                    case 'register.ftl':
                        return <Register kcContext={kcContext} {...rest} />
                    case 'delete-account-confirm.ftl':
                        return <DeleteAccountConfirm kcContext={kcContext} {...rest} />
                    case 'frontchannel-logout.ftl':
                        return <FrontchannelLogout kcContext={kcContext} {...rest} />
                    case 'delete-credential.ftl':
                        return <DeleteCredential kcContext={kcContext} {...rest} />
                    case 'code.ftl':
                        return <Code kcContext={kcContext} {...rest} />
                    default:
                        return null
                }
            })()}
        </Suspense>
    )
}
