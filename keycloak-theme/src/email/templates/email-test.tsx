/* eslint-disable react-refresh/only-export-components */
/**
 * El correo del botón «Probar conexión» del panel de Keycloak. No lleva botón
 * ni enlace: sólo sirve para comprobar que el SMTP del realm entrega.
 */
import { Heading, Text } from 'jsx-email'
import { render, renderPlainText } from 'jsx-email'
import { type GetSubject, type GetTemplate } from 'keycloakify-emails'
import { EmailLayout, styles } from '../components/EmailLayout.tsx'
import { getTranslations } from '../i18n.ts'

function EmailTest({ locale }: { locale: string }) {
    const t = getTranslations(locale)

    return (
        <EmailLayout preview={t.emailTest.preview} lang={locale} footer={t.footer}>
            <Heading style={styles.heading}>{t.emailTest.heading}</Heading>
            <Text style={styles.text}>{t.emailTest.body}</Text>
        </EmailLayout>
    )
}

export const getTemplate: GetTemplate = async ({ locale, plainText }) => {
    const component = <EmailTest locale={locale} />

    return plainText ? renderPlainText(component) : render(component)
}

export const getSubject: GetSubject = async ({ locale }) => getTranslations(locale).emailTest.subject
