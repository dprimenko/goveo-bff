/* eslint-disable react-refresh/only-export-components */
/**
 * Confirmación de la dirección. No lo usamos todavía —las cuentas nacen con el
 * correo dado por bueno—, pero Keycloak lo manda en cuanto alguien active
 * «Verify email» en el realm, y sin plantilla propia saldría el de serie: en
 * inglés y sin la marca.
 */
import { Heading, Section, Text } from 'jsx-email'
import { render, renderPlainText } from 'jsx-email'
import { type GetSubject, type GetTemplate } from 'keycloakify-emails'
import { createVariablesHelper } from 'keycloakify-emails/variables'
import { EmailLayout, styles } from '../components/EmailLayout.tsx'
import { Button } from '../components/Button.tsx'
import { getTranslations } from '../i18n.ts'

const { exp } = createVariablesHelper('email-verification.ftl')

function EmailVerification({ locale }: { locale: string }) {
    const t = getTranslations(locale)
    const tv = t.emailVerification

    return (
        <EmailLayout preview={tv.preview} lang={locale} footer={t.footer}>
            <Heading style={styles.heading}>{tv.heading}</Heading>
            <Text style={styles.text}>{tv.greeting}</Text>
            <Text style={styles.text}>{tv.body}</Text>
            <Section style={styles.buttonSection}>
                <Button href={exp('link')}>{tv.cta}</Button>
            </Section>
            <Text style={styles.fineprint}>
                {tv.expiry} {exp('linkExpirationFormatter(linkExpiration)')}.
            </Text>
            <Text style={styles.text}>{tv.ignore}</Text>
        </EmailLayout>
    )
}

export const getTemplate: GetTemplate = async ({ locale, plainText }) => {
    const component = <EmailVerification locale={locale} />

    return plainText ? renderPlainText(component) : render(component)
}

export const getSubject: GetSubject = async ({ locale }) => getTranslations(locale).emailVerification.subject
